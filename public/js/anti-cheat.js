/**
 * LearnAdapt Anti-Cheat Suite v3
 * Detects: tab switch, focus loss, screenshot, copy/paste, phone (AI camera),
 *          sustained voice/talking.
 */
'use strict';
(function (global) {

  /* ── Tuning ─────────────────────────────────────────────────────── */
  var HEARTBEAT_MS    = 5000;
  var TAB_GRACE_MS    = 2000;   // tab hidden this long → flag
  var FOCUS_GRACE_MS  = 4000;   // focus lost this long → flag
  var VOICE_THRESHOLD = 20;     // RMS level 0-255
  var VOICE_SUSTAIN_S = 8;      // seconds continuous talking

  /* ── State ───────────────────────────────────────────────────────── */
  var cfg            = {};
  var terminated     = false;
  var heartbeatTimer = null;
  var overlayEl      = null;
  var overlayMsgEl   = null;
  var tabHideTimer   = null;
  var focusLostTimer = null;

  /* ── Public API ──────────────────────────────────────────────────── */
  var AntiCheat = {
    init: function(options) {
      cfg = options;
      _buildOverlay();
      _startCameraMonitoring();
      _attachVisibilityGuard();
      _attachFocusGuard();
      _attachScreenshotGuard();
      _attachCopyPasteGuard();
      _attachVoiceGuard();
      _startHeartbeat();
    },
    onQuizSubmit: function() {
      terminated = true;
      _stopHeartbeat();
      _fetch(cfg.stopMonUrl, { attempt_id: cfg.attemptId });
    }
  };

  /* ── Overlay ─────────────────────────────────────────────────────── */
  function _buildOverlay() {
    overlayEl = document.createElement('div');
    overlayEl.id = 'ac-overlay';
    overlayEl.style.cssText =
      'position:fixed;inset:0;z-index:99999;' +
      'display:none;align-items:center;justify-content:center;' +
      'background:rgba(0,0,0,.93);backdrop-filter:blur(10px);' +
      'font-family:Inter,sans-serif;';
    overlayEl.innerHTML =
      '<div style="background:linear-gradient(145deg,rgba(17,21,33,.99),rgba(10,13,23,.99));' +
      'border:1px solid rgba(248,113,113,.35);border-radius:20px;padding:44px 40px;' +
      'max-width:460px;width:90%;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.7)">' +
      '<div style="font-size:3.2rem;margin-bottom:14px">\uD83D\uDEA8</div>' +
      '<h2 style="color:#f87171;font-size:1.55rem;font-weight:800;margin-bottom:10px">Cheating Detected</h2>' +
      '<p id="ac-overlay-msg" style="color:#8896ab;font-size:.97rem;line-height:1.6;margin-bottom:28px">A violation was detected.</p>' +
      '<p style="color:#f87171;font-weight:700;font-size:.97rem">Your score has been set to <strong>0</strong>.<br>Redirecting to results...</p>' +
      '</div>';
    document.body.appendChild(overlayEl);
    overlayMsgEl = document.getElementById('ac-overlay-msg');
  }

  function _showOverlay(message) {
    if (overlayEl) {
      overlayMsgEl.textContent = message;
      overlayEl.style.display  = 'flex';
    }
  }

  var CHEAT_LABELS = {
    tab_switch:     'You switched to another tab or window. This is not allowed during the exam.',
    screenshot:     'Screenshot attempt detected. This is strictly prohibited.',
    phone_detected: 'A phone was detected by the AI camera system. Phones are not allowed.',
    camera_denied:  'Camera access was denied. Camera monitoring is required.',
    focus_lost:     'You left the exam window. It must remain your active window at all times.',
    copy_paste:     'Copy or paste detected. Copying exam content or pasting answers is not allowed.',
    voice_detected: 'Sustained voice activity detected. Talking during an exam is not allowed.'
  };

  /* ── Report cheat — ALWAYS redirects, never depends on server ────── */
  function _reportCheat(cheatType) {
    if (terminated) return;
    terminated = true;
    _stopHeartbeat();

    var label = CHEAT_LABELS[cheatType] || 'A cheating attempt was detected.';
    _showOverlay(label);

    /* Hard redirect after 4 s regardless of server */
    var hardTimer = setTimeout(function() {
      window.location.href = cfg.resultsUrl;
    }, 4000);

    /* Notify server; if it replies OK redirect in 2.5 s */
    _fetch(cfg.cheatUrl, {
      attempt_id: cfg.attemptId,
      student_id: cfg.studentId,
      cheat_type: cheatType,
      source:     'js_client',
      timestamp:  Math.floor(Date.now() / 1000)
    }).then(function(data) {
      if (data && data.ok) {
        clearTimeout(hardTimer);
        setTimeout(function() { window.location.href = cfg.resultsUrl; }, 2500);
      }
    });

    _fetch(cfg.stopMonUrl, { attempt_id: cfg.attemptId });
  }

  /* ── 1. Tab switch ───────────────────────────────────────────────── */
  function _attachVisibilityGuard() {
    document.addEventListener('visibilitychange', function() {
      if (terminated) return;
      if (document.hidden) {
        tabHideTimer = setTimeout(function() {
          if (!terminated && document.hidden) _reportCheat('tab_switch');
        }, TAB_GRACE_MS);
      } else {
        clearTimeout(tabHideTimer);
      }
    });
  }

  /* ── 2. Focus lost ───────────────────────────────────────────────── */
  function _attachFocusGuard() {
    window.addEventListener('blur', function() {
      if (terminated) return;
      focusLostTimer = setTimeout(function() {
        if (!terminated) _reportCheat('focus_lost');
      }, FOCUS_GRACE_MS);
    });
    window.addEventListener('focus', function() { clearTimeout(focusLostTimer); });
  }

  /* ── 3. Screenshot ───────────────────────────────────────────────── */
  function _attachScreenshotGuard() {
    /* keydown fires before the screenshot; keyup fires after — catch both
       so we detect it regardless of browser/OS ordering.              */
    function _checkShot(e) {
      if (terminated) return;
      var code = e.code || '';
      var key  = (e.key || '').toLowerCase();
      var isShot = (
        code === 'PrintScreen' ||
        (e.altKey  && code === 'PrintScreen') ||
        (e.ctrlKey && code === 'PrintScreen') ||
        /* macOS: Cmd+Shift+3/4/5 */
        (e.metaKey && e.shiftKey && ['3','4','5'].indexOf(e.key) !== -1) ||
        /* Windows Snipping Tool: Win+Shift+S  (metaKey = Win key) */
        (e.metaKey && e.shiftKey && key === 's')
      );
      if (isShot) {
        e.preventDefault();
        e.stopImmediatePropagation();
        _reportCheat('screenshot');
      }
    }
    document.addEventListener('keydown', _checkShot, true);
    document.addEventListener('keyup',   _checkShot, true);
  }

  /* ── 4. Copy / Paste ─────────────────────────────────────────────── */
  function _attachCopyPasteGuard() {
    ['copy','cut','paste'].forEach(function(evt) {
      document.addEventListener(evt, function(e) {
        if (terminated) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        _reportCheat('copy_paste');
      }, true);
    });

    document.addEventListener('contextmenu', function(e) {
      if (!terminated) e.preventDefault();
    });

    document.addEventListener('keydown', function(e) {
      if (terminated) return;
      if (!(e.ctrlKey || e.metaKey)) return;
      var k   = e.key.toLowerCase();
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
      if (k === 'a' && (tag === 'input' || tag === 'textarea')) return;
      if (k === 'c' || k === 'x' || k === 'v') {
        e.preventDefault();
        e.stopImmediatePropagation();
        _reportCheat('copy_paste');
      } else if (k === 'a') {
        e.preventDefault();
      }
    }, true);
  }

  /* ── 5. Voice / talking guard ────────────────────────────────────── */
  /* Delayed 5 s so browser mic permission popup doesn't fire focus guard */
  function _attachVoiceGuard() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
    setTimeout(function() {
      if (terminated) return;
      navigator.mediaDevices.getUserMedia({ audio: true, video: false })
        .then(function(stream) {
          var AudioCtx = window.AudioContext || window.webkitAudioContext;
          if (!AudioCtx) { stream.getTracks().forEach(function(t) { t.stop(); }); return; }
          var ctx      = new AudioCtx();
          var src      = ctx.createMediaStreamSource(stream);
          var analyser = ctx.createAnalyser();
          analyser.fftSize               = 512;
          analyser.smoothingTimeConstant = 0.4;
          src.connect(analyser);
          var buf          = new Uint8Array(analyser.frequencyBinCount);
          var voiceStart   = null;
          var poll = setInterval(function() {
            if (terminated) {
              clearInterval(poll);
              stream.getTracks().forEach(function(t) { t.stop(); });
              ctx.close();
              return;
            }
            analyser.getByteFrequencyData(buf);
            var voiceBins = Array.prototype.slice.call(buf, 4, 40);
            var sum = voiceBins.reduce(function(s, v) { return s + v * v; }, 0);
            var rms = Math.sqrt(sum / voiceBins.length);
            if (rms > VOICE_THRESHOLD) {
              if (!voiceStart) {
                voiceStart = Date.now();
              } else if ((Date.now() - voiceStart) / 1000 >= VOICE_SUSTAIN_S) {
                clearInterval(poll);
                stream.getTracks().forEach(function(t) { t.stop(); });
                _reportCheat('voice_detected');
              }
            } else {
              voiceStart = null;
            }
          }, 500);
        })
        .catch(function() { /* mic denied - skip silently */ });
    }, 5000);
  }

  /* ── 6. Heartbeat (phone camera check) ──────────────────────────── */
  function _startHeartbeat() {
    heartbeatTimer = setInterval(function() {
      if (terminated) return;
      _fetch(cfg.heartbeatUrl, { attempt_id: cfg.attemptId })
        .then(function(data) {
          if (data && data.cheat_ended) {
            terminated = true;
            _stopHeartbeat();
            _showOverlay(CHEAT_LABELS['phone_detected']);
            setTimeout(function() { window.location.href = cfg.resultsUrl; }, 2500);
          }
        });
    }, HEARTBEAT_MS);
  }

  function _stopHeartbeat() {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }

  /* ── 7. Camera monitoring ────────────────────────────────────────── */
  function _startCameraMonitoring() {
    _fetch(cfg.startMonUrl, {
      attempt_id: cfg.attemptId,
      quiz_id:    cfg.quizId
    }).then(function(data) {
      /* If PHP says camera monitoring failed to start, it already logged
         camera_denied in the DB. The next heartbeat will pick it up and
         redirect. Nothing more to do here. */
      if (data && !data.ok) {
        console.warn('[AntiCheat] Camera monitoring unavailable — camera_denied logged server-side');
      }
    });
  }

  /* ── Util: JSON POST ─────────────────────────────────────────────── */
  function _fetch(url, body) {
    return fetch(url, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(body),
      keepalive:   true
    }).then(function(res) {
      if (!res.ok) return null;
      return res.json();
    }).catch(function() { return null; });
  }

  global.AntiCheat = AntiCheat;

})(window);
