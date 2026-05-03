/**
 * LearnAdapt Anti-Cheat Suite — anti-cheat.js
 * Monitors: phone detection (AI camera), tab switching, and screenshots only.
 * AntiCheat.init({ attemptId, studentId, quizId, resultsUrl,
 *                  cheatUrl, heartbeatUrl, startMonUrl, stopMonUrl });
 */

'use strict';

(function (global) {
  /* ─── Tuning ─────────────────────────────────────────────────────────── */
  const HEARTBEAT_MS   = 5_000;
  // How long the tab must be hidden before it counts as a violation
  const TAB_GRACE_MS   = 3_000;
  // How long the window must lose focus before flagging (screen sharing, alt-tab, etc.)
  const FOCUS_GRACE_MS = 5_000;

  /* ─── State ──────────────────────────────────────────────────────────── */
  let cfg            = {};
  let terminated     = false;
  let heartbeatTimer = null;
  let overlayEl      = null;
  let overlayMsgEl   = null;
  let tabHideTimer   = null;
  let focusLostTimer = null;

  /* ══════════════════════════════════════════════════════════════════════
     PUBLIC API
  ══════════════════════════════════════════════════════════════════════ */
  const AntiCheat = {
    init(options) {
      cfg = Object.assign({}, options);
      _buildOverlay();
      _startCameraMonitoring();
      _attachVisibilityGuard();
      _attachFocusGuard();
      _attachScreenshotGuard();
      _startHeartbeat();
    },
    onQuizSubmit() {
      terminated = true;
      _stopHeartbeat();
      _fetch(cfg.stopMonUrl, { attempt_id: cfg.attemptId });
    },
  };

  /* ══════════════════════════════════════════════════════════════════════
     OVERLAY — shown when cheat is confirmed
  ══════════════════════════════════════════════════════════════════════ */
  function _buildOverlay() {
    overlayEl = document.createElement('div');
    overlayEl.id = 'ac-overlay';
    overlayEl.style.cssText = [
      'position:fixed;inset:0;z-index:99999',
      'display:none;align-items:center;justify-content:center',
      'background:rgba(0,0,0,.93);backdrop-filter:blur(10px)',
      'font-family:Inter,sans-serif',
    ].join(';');

    overlayEl.innerHTML = `
      <div style="
        background:linear-gradient(145deg,rgba(17,21,33,.99),rgba(10,13,23,.99));
        border:1px solid rgba(248,113,113,.35);border-radius:20px;
        padding:44px 40px;max-width:460px;width:90%;text-align:center;
        box-shadow:0 30px 80px rgba(0,0,0,.7)">
        <div style="font-size:3.2rem;margin-bottom:14px">🚨</div>
        <h2 style="color:#f87171;font-size:1.55rem;font-weight:800;margin-bottom:10px">
          Cheating Detected
        </h2>
        <p id="ac-overlay-msg" style="color:#8896ab;font-size:.97rem;line-height:1.6;margin-bottom:28px">
          A violation was detected.
        </p>
        <p style="color:#f87171;font-weight:700;font-size:.97rem">
          Your score has been set to <strong>0</strong>.<br>Redirecting to results…
        </p>
      </div>`;

    document.body.appendChild(overlayEl);
    overlayMsgEl = document.getElementById('ac-overlay-msg');
  }

  function _showOverlay(message) {
    if (overlayEl) {
      overlayMsgEl.textContent = message;
      overlayEl.style.display = 'flex';
    }
  }

  /* ══════════════════════════════════════════════════════════════════════
     CHEAT MESSAGES
  ══════════════════════════════════════════════════════════════════════ */
  const CHEAT_LABELS = {
    tab_switch:     'You left the exam page and switched to another tab or window. This is not allowed during the exam.',
    screenshot:     'You attempted to take a screenshot of the exam. This is strictly prohibited.',
    phone_detected: 'A phone was detected by the AI camera monitoring system. Phone use during exams is strictly prohibited.',
    camera_denied:  'You denied camera access to the proctoring system. Camera monitoring is required to take this exam.',
    focus_lost:     'You stopped focusing on the exam window. The exam must remain your active window at all times.',
  };

  function _reportCheat(cheatType) {
    if (terminated) return;
    terminated = true;

    const label = CHEAT_LABELS[cheatType] || 'A cheating attempt was detected.';
    _showOverlay(label);

    _fetch(cfg.cheatUrl, {
      attempt_id: cfg.attemptId,
      student_id: cfg.studentId,
      cheat_type: cheatType,
      source:     'js_client',
      timestamp:  Math.floor(Date.now() / 1000),
    }).then(data => {
      if (data && data.terminated) {
        setTimeout(() => { window.location.href = cfg.resultsUrl; }, 3500);
      }
    }).catch(() => {
      setTimeout(() => { window.location.href = cfg.resultsUrl; }, 4000);
    });

    _stopHeartbeat();
    _fetch(cfg.stopMonUrl, { attempt_id: cfg.attemptId });
  }

  /* ══════════════════════════════════════════════════════════════════════
     1. TAB / PAGE SWITCH GUARD
     The tab must be hidden for TAB_GRACE_MS before flagging.
  ══════════════════════════════════════════════════════════════════════ */
  function _attachVisibilityGuard() {
    document.addEventListener('visibilitychange', () => {
      if (terminated) return;

      if (document.hidden) {
        tabHideTimer = setTimeout(() => {
          if (!terminated && document.hidden) {
            _reportCheat('tab_switch');
          }
        }, TAB_GRACE_MS);
      } else {
        clearTimeout(tabHideTimer);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     2. FOCUS GUARD — detects user switching away (screen-sharing/alt-tab)
     Uses a generous grace period to avoid false positives from
     notifications, system dialogs, etc.
  ══════════════════════════════════════════════════════════════════════ */
  function _attachFocusGuard() {
    window.addEventListener('blur', () => {
      if (terminated) return;
      // Only flag if focus is gone for longer than FOCUS_GRACE_MS
      focusLostTimer = setTimeout(() => {
        if (!terminated) {
          _reportCheat('focus_lost');
        }
      }, FOCUS_GRACE_MS);
    });

    window.addEventListener('focus', () => {
      clearTimeout(focusLostTimer);
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     3. SCREENSHOT GUARD — keyboard PrintScreen key detection
  ══════════════════════════════════════════════════════════════════════ */
  function _attachScreenshotGuard() {
    document.addEventListener('keydown', e => {
      if (terminated) return;
      const code  = e.code || '';
      const isScreenshot = (
        code === 'PrintScreen'
        || (e.altKey  && code === 'PrintScreen')
        || (e.ctrlKey && code === 'PrintScreen')
        || (e.metaKey && e.shiftKey && (e.key === '3' || e.key === '4' || e.key === '5'))
      );
      if (isScreenshot) {
        e.preventDefault();
        e.stopImmediatePropagation();
        _reportCheat('screenshot');
      }
    }, { capture: true });
  }

  /* ══════════════════════════════════════════════════════════════════════
     4. HEARTBEAT — polls server; terminates if Python camera flags cheat
  ══════════════════════════════════════════════════════════════════════ */
  function _startHeartbeat() {
    heartbeatTimer = setInterval(async () => {
      if (terminated) return;
      try {
        const data = await _fetch(cfg.heartbeatUrl, { attempt_id: cfg.attemptId });
        if (data && data.cheat_ended) {
          terminated = true;
          _stopHeartbeat();
          _showOverlay(CHEAT_LABELS['phone_detected']);
          setTimeout(() => { window.location.href = cfg.resultsUrl; }, 3000);
        }
      } catch (_) { /* network blip — no penalty */ }
    }, HEARTBEAT_MS);
  }

  function _stopHeartbeat() {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }

  /* ══════════════════════════════════════════════════════════════════════
     5. CAMERA MONITORING START (Python AI service)
  ══════════════════════════════════════════════════════════════════════ */
  function _startCameraMonitoring() {
    _fetch(cfg.startMonUrl, {
      attempt_id: cfg.attemptId,
      quiz_id:    cfg.quizId,
    }).catch(() => { /* Python service down — JS monitoring still active */ });
  }

  /* ══════════════════════════════════════════════════════════════════════
     UTIL: JSON POST
  ══════════════════════════════════════════════════════════════════════ */
  async function _fetch(url, body) {
    const res = await fetch(url, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(body),
      keepalive:   true,
    });
    if (!res.ok) return null;
    return res.json();
  }

  global.AntiCheat = AntiCheat;
})(window);
