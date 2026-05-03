"""
main.py — LearnAdapt Anti-Cheat Python Service
================================================
FastAPI service that:
  • Manages camera monitoring sessions (per student per quiz)
  • Detects phones via YOLOv8 / MediaPipe
  • Forwards cheat events to the Symfony backend
  • Exposes REST endpoints consumed by Symfony
  • Verifies all requests with a shared HMAC secret

Run:
    uvicorn main:app --host 0.0.0.0 --port 8765 --workers 1
"""

from __future__ import annotations

import hashlib
import hmac
import logging
import os
import time
from contextlib import asynccontextmanager
from typing import Dict, Optional

import httpx
from dotenv import load_dotenv
from fastapi import BackgroundTasks, Depends, FastAPI, Header, HTTPException, Request, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from phone_detector import CameraMonitorSession

# ---------------------------------------------------------------------------
# Bootstrap
# ---------------------------------------------------------------------------
load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

SYMFONY_BASE_URL: str = os.environ["SYMFONY_BASE_URL"].rstrip("/")
SHARED_SECRET: str = os.environ["ANTI_CHEAT_SECRET"]
CAMERA_INDEX: int = int(os.getenv("CAMERA_INDEX", "0"))

# In-memory session registry {"{quiz_id}:{student_id}": CameraMonitorSession}
_sessions: Dict[str, CameraMonitorSession] = {}


# ---------------------------------------------------------------------------
# App lifespan
# ---------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Anti-cheat service starting — Symfony at %s", SYMFONY_BASE_URL)
    yield
    # Graceful shutdown: stop all monitoring sessions
    for key, session in list(_sessions.items()):
        session.stop()
        logger.info("Session %s stopped on shutdown", key)
    _sessions.clear()


app = FastAPI(
    title="LearnAdapt Anti-Cheat Service",
    version="1.0.0",
    lifespan=lifespan,
    docs_url=None,  # Disable Swagger in production (controlled by env)
    redoc_url=None,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[SYMFONY_BASE_URL],
    allow_methods=["POST", "GET"],
    allow_headers=["X-Anti-Cheat-Signature", "Content-Type"],
)


# ---------------------------------------------------------------------------
# Security: HMAC signature verification
# ---------------------------------------------------------------------------
def _compute_signature(body: bytes) -> str:
    return hmac.new(SHARED_SECRET.encode(), body, hashlib.sha256).hexdigest()


async def verify_signature(
    request: Request,
    x_anti_cheat_signature: Optional[str] = Header(default=None),
) -> None:
    body = await request.body()
    if not x_anti_cheat_signature:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing signature")
    expected = _compute_signature(body)
    if not hmac.compare_digest(expected, x_anti_cheat_signature):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Invalid signature")


# ---------------------------------------------------------------------------
# Helper: forward cheat event to Symfony
# ---------------------------------------------------------------------------
async def _notify_symfony(quiz_attempt_id: int, student_id: int, cheat_type: str) -> None:
    payload = {
        "attempt_id": quiz_attempt_id,
        "student_id": student_id,
        "cheat_type": cheat_type,
        "source": "python_service",
        "timestamp": int(time.time()),
    }
    import json

    body = json.dumps(payload).encode()
    sig = _compute_signature(body)

    url = f"{SYMFONY_BASE_URL}/api/quiz/cheat-detected"
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.post(
                url,
                content=body,
                headers={
                    "Content-Type": "application/json",
                    "X-Anti-Cheat-Signature": sig,
                },
            )
            if resp.status_code not in (200, 204):
                logger.error(
                    "Symfony rejected cheat notification: %s — %s",
                    resp.status_code,
                    resp.text,
                )
            else:
                logger.info(
                    "Cheat '%s' sent to Symfony for attempt %d student %d",
                    cheat_type,
                    quiz_attempt_id,
                    student_id,
                )
    except httpx.RequestError as exc:
        logger.error("Failed to reach Symfony: %s", exc)


# ---------------------------------------------------------------------------
# Pydantic models
# ---------------------------------------------------------------------------
class StartMonitoringRequest(BaseModel):
    quiz_attempt_id: int = Field(..., gt=0)
    quiz_id: int = Field(..., gt=0)
    student_id: int = Field(..., gt=0)
    session_token: str = Field(..., min_length=8)


class StopMonitoringRequest(BaseModel):
    quiz_attempt_id: int = Field(..., gt=0)
    student_id: int = Field(..., gt=0)


class CheatEventRequest(BaseModel):
    quiz_attempt_id: int = Field(..., gt=0)
    student_id: int = Field(..., gt=0)
    cheat_type: str = Field(..., min_length=1, max_length=64)
    timestamp: int = Field(..., gt=0)


class StatusResponse(BaseModel):
    ok: bool
    message: str


# ---------------------------------------------------------------------------
# AI Student Analysis Models
# ---------------------------------------------------------------------------
class StudentDataPayload(BaseModel):
    student_id: int
    quiz_scores: list[float] = []        # list of score_percent values (0-100)
    course_progress: list[float] = []    # list of progress_percent values (0-100)
    completed_courses: int = 0
    total_enrolled: int = 0
    streak_days: int = 0
    cheat_count: int = 0                 # number of cheating attempts


class AIAnalysisResponse(BaseModel):
    student_id: int
    engagement_score: float              # 0-100
    learning_speed: str                  # "fast" | "normal" | "slow"
    risk_level: str                      # "low" | "medium" | "high"
    risk_score: float                    # 0-100 (higher = more at risk)
    success_probability: float           # 0-100
    student_profile: str                 # "excellent" | "average" | "struggling"
    recommended_difficulty: str          # "easy" | "medium" | "hard"
    strengths: list[str]
    improvement_areas: list[str]
    recommendations: list[str]
    adaptive_message: str


# ---------------------------------------------------------------------------
# AI Content Moderation Models
# ---------------------------------------------------------------------------
class ModerationRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=4096)
    language: str = "en"


class ModerationResponse(BaseModel):
    toxicity_score: float          # 0.0 – 1.0
    categories: dict               # raw per-category scores from detoxify
    classification: str            # "normal" | "toxic" | "hateful" | "spam"
    action: str                    # "allow" | "pending" | "block"
    reason: str
# Endpoints
# ---------------------------------------------------------------------------
@app.post(
    "/start-monitoring",
    response_model=StatusResponse,
    dependencies=[Depends(verify_signature)],
)
async def start_monitoring(
    payload: StartMonitoringRequest,
    background_tasks: BackgroundTasks,
) -> StatusResponse:
    key = f"{payload.quiz_id}:{payload.student_id}"

    if key in _sessions:
        return StatusResponse(ok=True, message="Session already active")

    attempt_id = payload.quiz_attempt_id
    student_id = payload.student_id

    def on_phone_detected(quiz_id: int, sid: int) -> None:
        import asyncio

        loop = asyncio.new_event_loop()
        try:
            loop.run_until_complete(
                _notify_symfony(attempt_id, sid, "phone_detected")
            )
        finally:
            loop.close()
        # Remove session so monitoring stops
        _sessions.pop(key, None)

    session = CameraMonitorSession(
        quiz_id=payload.quiz_id,
        student_id=student_id,
        on_phone_detected=on_phone_detected,
        camera_index=CAMERA_INDEX,
    )

    camera_ok = session.start()
    if not camera_ok:
        # Camera unavailable — notify Symfony immediately (camera_denied cheat)
        background_tasks.add_task(
            _notify_symfony, attempt_id, student_id, "camera_denied"
        )
        return StatusResponse(
            ok=False, message="Camera could not be opened — cheat event sent"
        )

    _sessions[key] = session
    return StatusResponse(ok=True, message="Monitoring started")


@app.post(
    "/stop-monitoring",
    response_model=StatusResponse,
    dependencies=[Depends(verify_signature)],
)
async def stop_monitoring(payload: StopMonitoringRequest) -> StatusResponse:
    key_prefix = f":{payload.student_id}"
    stopped = []
    for key in list(_sessions.keys()):
        if key.endswith(key_prefix) or key == f"{payload.quiz_attempt_id}:{payload.student_id}":
            _sessions[key].stop()
            _sessions.pop(key, None)
            stopped.append(key)

    if stopped:
        return StatusResponse(ok=True, message=f"Stopped sessions: {stopped}")
    return StatusResponse(ok=True, message="No active session found")


@app.post(
    "/cheat-event",
    response_model=StatusResponse,
    dependencies=[Depends(verify_signature)],
)
async def receive_cheat_event(
    payload: CheatEventRequest,
    background_tasks: BackgroundTasks,
) -> StatusResponse:
    """
    Internal endpoint: receives cheat events forwarded by Symfony from the
    browser JS layer and re-broadcasts them back to Symfony's processing
    endpoint. This allows Symfony to use a single internal pathway.
    """
    background_tasks.add_task(
        _notify_symfony,
        payload.quiz_attempt_id,
        payload.student_id,
        payload.cheat_type,
    )
    return StatusResponse(ok=True, message="Cheat event queued")


@app.get("/health")
async def health() -> dict:
    return {
        "status": "ok",
        "active_sessions": len(_sessions),
        "sessions": list(_sessions.keys()),
    }


# ---------------------------------------------------------------------------
# AI Student Analysis Endpoint
# ---------------------------------------------------------------------------
@app.post(
    "/analyze-student",
    response_model=AIAnalysisResponse,
    dependencies=[Depends(verify_signature)],
)
async def analyze_student(payload: StudentDataPayload) -> AIAnalysisResponse:
    """
    Rule-based + weighted ML-style student profiling.
    No external ML library needed — pure statistical rules on real DB data.
    """
    scores = payload.quiz_scores or []
    progress = payload.course_progress or []

    # ── Core metrics ──────────────────────────────────────────────────────
    avg_score     = sum(scores) / len(scores) if scores else 0.0
    avg_progress  = sum(progress) / len(progress) if progress else 0.0
    completion_rate = (
        (payload.completed_courses / payload.total_enrolled * 100)
        if payload.total_enrolled > 0 else 0.0
    )
    score_variance = (
        (sum((s - avg_score) ** 2 for s in scores) / len(scores)) ** 0.5
        if len(scores) > 1 else 0.0
    )

    # ── Engagement score (0–100) ──────────────────────────────────────────
    # Weighted: streak 30% | completion_rate 25% | avg_progress 25% | quiz activity 20%
    streak_norm   = min(payload.streak_days / 30.0, 1.0) * 100
    quiz_activity = min(len(scores) / 10.0, 1.0) * 100
    engagement_score = (
        streak_norm      * 0.30 +
        completion_rate  * 0.25 +
        avg_progress     * 0.25 +
        quiz_activity    * 0.20
    )
    engagement_score = round(min(engagement_score, 100.0), 1)

    # ── Learning speed ────────────────────────────────────────────────────
    # Based on how quickly they complete courses + quiz count relative to enrolled
    quiz_per_course = len(scores) / max(payload.total_enrolled, 1)
    if quiz_per_course >= 2.5 and completion_rate >= 60:
        learning_speed = "fast"
    elif quiz_per_course >= 1.0 or completion_rate >= 30:
        learning_speed = "normal"
    else:
        learning_speed = "slow"

    # ── Risk score (0–100, higher = more at risk) ─────────────────────────
    risk_score = 0.0
    if avg_score < 40:       risk_score += 35
    elif avg_score < 60:     risk_score += 20
    if completion_rate < 20: risk_score += 25
    elif completion_rate < 40: risk_score += 12
    if avg_progress < 25:    risk_score += 15
    if payload.streak_days == 0: risk_score += 10
    if payload.cheat_count > 0:  risk_score += min(payload.cheat_count * 8, 20)
    if score_variance > 30:  risk_score += 5  # inconsistent
    risk_score = round(min(risk_score, 100.0), 1)

    # ── Risk level ────────────────────────────────────────────────────────
    if risk_score >= 60:     risk_level = "high"
    elif risk_score >= 30:   risk_level = "medium"
    else:                    risk_level = "low"

    # ── Success probability (0–100) ───────────────────────────────────────
    success_probability = round(max(0.0, min(100.0,
        avg_score        * 0.40 +
        completion_rate  * 0.25 +
        engagement_score * 0.20 +
        (100 - risk_score) * 0.15
    )), 1)

    # ── Student profile ───────────────────────────────────────────────────
    if success_probability >= 70 and risk_level == "low":
        student_profile = "excellent"
        recommended_difficulty = "hard"
    elif success_probability >= 45 or risk_level == "medium":
        student_profile = "average"
        recommended_difficulty = "medium"
    else:
        student_profile = "struggling"
        recommended_difficulty = "easy"

    # ── Strengths & improvement areas ────────────────────────────────────
    strengths: list[str] = []
    improvement_areas: list[str] = []

    if avg_score >= 75:           strengths.append("Strong quiz performance")
    if completion_rate >= 60:     strengths.append("High course completion rate")
    if payload.streak_days >= 7:  strengths.append(f"{payload.streak_days}-day learning streak")
    if engagement_score >= 70:    strengths.append("Excellent engagement level")
    if learning_speed == "fast":  strengths.append("Fast learner")
    if score_variance < 15:       strengths.append("Consistent performance")

    if avg_score < 60:            improvement_areas.append("Quiz scores below target (aim for 60%+)")
    if completion_rate < 40:      improvement_areas.append("Complete more enrolled courses")
    if payload.streak_days == 0:  improvement_areas.append("Start a daily learning streak")
    if avg_progress < 40:         improvement_areas.append("Increase average course progress")
    if len(scores) < 3:           improvement_areas.append("Attempt more quizzes to build your profile")
    if payload.cheat_count > 0:   improvement_areas.append("Academic integrity: cheating attempts detected")

    if not strengths:             strengths.append("Getting started — keep exploring!")
    if not improvement_areas:     improvement_areas.append("No major gaps detected — keep it up!")

    # ── Personalised recommendations ─────────────────────────────────────
    recommendations: list[str] = []
    if student_profile == "excellent":
        recommendations = [
            "Challenge yourself with Hard-level courses",
            "Explore advanced topics in your strongest modules",
            "Consider mentoring peers — it reinforces your own knowledge",
            "Aim for 100% completion on your enrolled courses",
        ]
        adaptive_message = "🏆 Outstanding! Your performance is excellent. We've unlocked advanced content tailored for you."
    elif student_profile == "average":
        recommendations = [
            "Focus on completing courses already in progress",
            "Review quiz topics where your scores dipped below 60%",
            "Try to maintain a daily learning streak",
            "Revisit Medium-difficulty courses to solidify fundamentals",
        ]
        adaptive_message = "📈 Good progress! A few targeted improvements will take you to the next level."
    else:
        recommendations = [
            "Start with Easy courses to build confidence",
            "Spend at least 20 minutes studying each day",
            "Retake quizzes you struggled with — practice makes perfect",
            "Use the bookmarks feature to save courses for later review",
            "Reach out if you need support — you're not alone!",
        ]
        adaptive_message = "💪 Don't give up! Your AI tutor has prepared simpler resources to help you get back on track."

    return AIAnalysisResponse(
        student_id=payload.student_id,
        engagement_score=engagement_score,
        learning_speed=learning_speed,
        risk_level=risk_level,
        risk_score=risk_score,
        success_probability=success_probability,
        student_profile=student_profile,
        recommended_difficulty=recommended_difficulty,
        strengths=strengths,
        improvement_areas=improvement_areas,
        recommendations=recommendations,
        adaptive_message=adaptive_message,
    )


# ---------------------------------------------------------------------------
# AI Content Moderation Endpoint
# ---------------------------------------------------------------------------

# Lazy-load detoxify model once (cached after first call)
_detoxify_model = None
_detoxify_available = None   # None = untested, True/False = known


def _get_detoxify():
    global _detoxify_model, _detoxify_available
    if _detoxify_available is False:
        return None
    if _detoxify_model is not None:
        return _detoxify_model
    try:
        from detoxify import Detoxify  # type: ignore
        _detoxify_model = Detoxify("original")
        _detoxify_available = True
        logger.info("detoxify model loaded (original)")
        return _detoxify_model
    except Exception as exc:
        _detoxify_available = False
        logger.warning("detoxify not available, using keyword fallback: %s", exc)
        return None


# Keyword-based fallback (no ML dependency)
_SPAM_PATTERNS = [
    "click here", "buy now", "free money", "limited offer", "earn online",
    "work from home", "make money fast", "guaranteed profit", "bit.ly", "tinyurl",
]
_HATE_KEYWORDS = [
    "kill yourself", "kys", "go die", "i hate you all", "you should die",
]
_TOXIC_KEYWORDS = [
    "idiot", "moron", "stupid", "retard", "loser", "pathetic", "trash", "garbage",
    "dumb", "worthless", "shut up", "shut the", "go away", "nobody cares",
]


def _keyword_analyze(text: str) -> ModerationResponse:
    lower = text.lower()
    hate_score = sum(1 for kw in _HATE_KEYWORDS if kw in lower) / max(len(_HATE_KEYWORDS), 1)
    spam_score = sum(1 for kw in _SPAM_PATTERNS if kw in lower) / max(len(_SPAM_PATTERNS), 1)
    toxic_score = sum(1 for kw in _TOXIC_KEYWORDS if kw in lower) / max(len(_TOXIC_KEYWORDS), 1)

    scores = {"toxic": toxic_score, "severe_toxic": hate_score, "spam": spam_score}
    max_score = max(scores.values())

    if hate_score > 0.05:
        classification, toxicity_score = "hateful", min(0.7 + hate_score * 3, 1.0)
    elif spam_score > 0.08:
        classification, toxicity_score = "spam", min(0.5 + spam_score * 2, 1.0)
    elif toxic_score > 0.05:
        classification, toxicity_score = "toxic", min(0.45 + toxic_score * 3, 1.0)
    else:
        classification, toxicity_score = "normal", round(max_score, 4)

    toxicity_score = round(toxicity_score, 4)

    if toxicity_score >= 0.80:
        action, reason = "block", f"Keyword analysis: {classification} content (score {toxicity_score:.2f})"
    elif toxicity_score >= 0.45:
        action, reason = "pending", f"Keyword analysis: possible {classification} content (score {toxicity_score:.2f})"
    else:
        action, reason = "allow", "Keyword analysis: content appears normal"

    return ModerationResponse(
        toxicity_score=toxicity_score,
        categories=scores,
        classification=classification,
        action=action,
        reason=reason,
    )


@app.post(
    "/moderate-content",
    response_model=ModerationResponse,
    dependencies=[Depends(verify_signature)],
)
async def moderate_content(payload: ModerationRequest) -> ModerationResponse:
    """
    Analyse forum text for toxicity / hate speech / spam.
    Uses detoxify (multilingual model) with keyword-based fallback.
    Thresholds: allow < 0.45, pending 0.45–0.80, block >= 0.80
    """
    text = payload.text.strip()
    if not text:
        return ModerationResponse(
            toxicity_score=0.0,
            categories={},
            classification="normal",
            action="allow",
            reason="Empty text",
        )

    model = _get_detoxify()
    if model is None:
        return _keyword_analyze(text)

    try:
        results: dict = model.predict(text)
        # Keys: toxicity, severe_toxicity, obscene, threat, insult, identity_attack
        toxicity_score = float(results.get("toxicity", 0.0))
        severe_score   = float(results.get("severe_toxicity", 0.0))
        threat_score   = float(results.get("threat", 0.0))
        insult_score   = float(results.get("insult", 0.0))
        identity_score = float(results.get("identity_attack", 0.0))
        obscene_score  = float(results.get("obscene", 0.0))

        # Boost overall if hate/threat signals are high
        combined = max(
            toxicity_score,
            severe_score * 1.4,
            threat_score * 1.5,
            identity_score * 1.3,
        )
        combined = round(min(combined, 1.0), 4)

        # Classify
        if identity_score > 0.35 or threat_score > 0.35 or severe_score > 0.30:
            classification = "hateful"
        elif insult_score > 0.55 or toxicity_score > 0.60:
            classification = "toxic"
        else:
            classification = "normal"

        # Decide action
        if combined >= 0.80:
            action = "block"
            reason = f"AI: {classification} content detected (score {combined:.2f})"
        elif combined >= 0.45:
            action = "pending"
            reason = f"AI: borderline content — {classification} (score {combined:.2f})"
        else:
            action = "allow"
            reason = "AI: content appears normal"

        categories = {k: round(float(v), 4) for k, v in results.items()}
        return ModerationResponse(
            toxicity_score=combined,
            categories=categories,
            classification=classification,
            action=action,
            reason=reason,
        )

    except Exception as exc:
        logger.error("detoxify prediction failed, using keyword fallback: %s", exc)
        return _keyword_analyze(text)
