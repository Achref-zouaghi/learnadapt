# LearnAdapt Anti-Cheat — Deployment Guide

## Overview

```
┌────────────────────┐   REST API (HMAC-signed)   ┌─────────────────────────┐
│  Symfony (PHP)     │ ◄─────────────────────────► │  Python FastAPI service  │
│  Quiz / Scoring    │                             │  Camera + Phone detect   │
└────────────────────┘                             └─────────────────────────┘
        ▲                                                    ▲
        │ fetch() (same-origin, session-auth)               │ OpenCV / YOLOv8
        ▼                                                    ▼
┌────────────────────┐                             ┌─────────────────────────┐
│  Browser JS        │                             │  Webcam (student PC)    │
│  anti-cheat.js     │                             └─────────────────────────┘
└────────────────────┘
```

---

## Step 1 — Environment Variables

### Symfony `.env` (add to bottom)

```dotenv
###> anti-cheat ###
# URL of the Python FastAPI service (running on same machine or local network)
ANTI_CHEAT_PYTHON_URL=http://127.0.0.1:8765

# HMAC-SHA256 shared secret — MUST match the Python service .env
# Generate: php -r "echo bin2hex(random_bytes(32));"
ANTI_CHEAT_SECRET=CHANGE_ME_GENERATE_A_STRONG_SECRET
###< anti-cheat ###
```

### Python service `python-anti-cheat/.env`

```dotenv
SYMFONY_BASE_URL=http://127.0.0.1:8000
ANTI_CHEAT_SECRET=CHANGE_ME_GENERATE_A_STRONG_SECRET   # same as Symfony
CAMERA_INDEX=0
YOLO_MODEL_PATH=yolov8n.pt
```

---

## Step 2 — Database Migration

```bash
# From the Symfony project root
php bin/console doctrine:migrations:migrate
```

This adds `cheat_flags` (JSON) and `cheat_ended` (TINYINT) columns to `quiz_attempts`.

---

## Step 3 — Python Service Setup

```bash
cd python-anti-cheat

# Create and activate virtual environment
python3 -m venv venv
source venv/bin/activate          # Linux/Mac
# .\venv\Scripts\Activate.ps1    # Windows PowerShell

# Install dependencies
pip install --upgrade pip
pip install -r requirements.txt

# Copy and fill in environment variables
cp .env.example .env
nano .env   # or use your editor

# First run: YOLOv8 will auto-download yolov8n.pt (~6 MB)
uvicorn main:app --host 127.0.0.1 --port 8765 --workers 1
```

### Verify the service is running

```bash
curl http://127.0.0.1:8765/health
# Expected: {"status":"ok","active_sessions":0,"sessions":[]}
```

---

## Step 4 — Run as a Background Service (Linux with Supervisor)

```bash
# Install supervisor
sudo apt install supervisor -y

# Edit the config file with correct paths
nano python-anti-cheat/supervisor.conf

# Copy to supervisor directory
sudo cp python-anti-cheat/supervisor.conf /etc/supervisor/conf.d/learnadapt-anticheat.conf

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start learnadapt-anticheat

# Check status
sudo supervisorctl status learnadapt-anticheat
```

### Run as a Windows Service (using NSSM)

```powershell
# Download NSSM from https://nssm.cc/
nssm install LearnAdaptAntiCheat "C:\path\to\venv\Scripts\uvicorn.exe" `
    "main:app --host 127.0.0.1 --port 8765 --workers 1"
nssm set LearnAdaptAntiCheat AppDirectory "C:\path\to\python-anti-cheat"
nssm start LearnAdaptAntiCheat
```

---

## Step 5 — Symfony Cache Clear

```bash
php bin/console cache:clear
```

---

## How It Works End-to-End

### Quiz Start
1. Student clicks "Start Quiz" → `QuizController::start()` creates a `quiz_attempt`
2. The `take.html.twig` page loads with `anti-cheat.js`
3. On `DOMContentLoaded`, JS calls `AntiCheat.init()` which:
   - Requests fullscreen (`requestFullscreen`)
   - Calls `POST /api/quiz/start-monitoring` → Symfony → Python opens webcam
   - Starts a 5 s heartbeat to `/api/quiz/heartbeat`
   - Attaches all keyboard, clipboard, visibility, and DevTools guards

### Phone Detected (Python)
1. Python captures a frame every 1.5 s, runs YOLOv8 inference
2. If phone detected → Python calls `POST /api/quiz/cheat-detected` with HMAC sig
3. Symfony `CheatDetectionManager::handleCheatEvent()` sets `score_percent=0`, `cheat_ended=1`, `finished_at=NOW()`
4. Next heartbeat (≤ 5 s) → JS receives `cheat_ended=true` → shows overlay → redirects to results

### Client-Side Cheat
1. Student presses Ctrl+C → JS captures event, prevents it, calls `_reportCheat('copy')`
2. JS POSTs to `POST /api/quiz/cheat-detected` with `source=js_client`
3. Symfony validates session, cross-checks `student_id` vs attempt owner, logs event
4. If cheat type is in `ZERO_SCORE_CHEATS` → score set to 0, quiz ended
5. JSON response `{terminated: true}` → JS shows overlay, redirects after 3.5 s

---

## Fallback (Python Service Down)

If the Python service is unreachable:
- `startCameraMonitoring()` returns `false` and logs a warning
- The quiz continues with **JS-only monitoring** (keyboard, tab-switch, fullscreen, DevTools)
- Camera monitoring is simply skipped — no camera_denied cheat is fired unless Python explicitly cannot open the camera

---

## Cheats Detected & Their Sources

| Cheat Type       | Source          | Score → 0? |
|-----------------|-----------------|------------|
| `phone_detected` | Python (YOLOv8) | ✅          |
| `camera_denied`  | Python          | ✅          |
| `tab_switch`     | JS              | ✅          |
| `copy`           | JS              | ✅          |
| `paste`          | JS              | ✅          |
| `cut`            | JS              | ✅          |
| `select_all`     | JS              | ✅          |
| `undo`           | JS              | ✅          |
| `redo`           | JS              | ✅          |
| `save`           | JS              | ✅          |
| `print`          | JS              | ✅          |
| `find`           | JS              | ✅          |
| `refresh`        | JS              | ✅          |
| `new_tab`        | JS              | ✅          |
| `new_window`     | JS              | ✅          |
| `screenshot`     | JS              | ✅          |
| `devtools`       | JS              | ✅          |
| `fullscreen_exit`| JS (2nd exit)   | ✅          |
| `right_click`    | JS              | ✅          |
| `browser_exit`   | JS              | ✅          |

---

## Admin: Viewing Cheat Logs

```sql
SELECT
    qa.id,
    u.email,
    dq.title AS quiz,
    qa.score_percent,
    qa.cheat_ended,
    qa.cheat_flags,
    qa.finished_at
FROM quiz_attempts qa
JOIN users u          ON u.id  = qa.student_user_id
JOIN diagnostic_quizzes dq ON dq.id = qa.quiz_id
WHERE qa.cheat_ended = 1
ORDER BY qa.finished_at DESC;
```

---

## Security Notes

- **HMAC signatures** prevent spoofing of Python → Symfony cheat events
- **Session authentication** prevents JS from faking another student's cheat report
- **`student_id` ownership check** in `CheatDetectionController` prevents cross-student manipulation
- **CSP headers** via NelmioSecurityBundle block inline script injection from the browser console
- **`X-Frame-Options: DENY`** prevents the quiz page from being embedded and controlled remotely
- The shared secret must be rotated if either service is compromised
