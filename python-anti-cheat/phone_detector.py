"""
phone_detector.py
-----------------
Phone detection using YOLOv8 (primary) with MediaPipe-based fallback.

The detector runs in a background thread, captures frames from the webcam
every CAPTURE_INTERVAL seconds, and calls the registered callback whenever
a phone is detected with confidence >= CONFIDENCE_THRESHOLD.
"""

from __future__ import annotations

import logging
import threading
import time
from typing import Callable, Optional

try:
    import cv2
    import numpy as np
    _CV2_AVAILABLE = True
except ImportError:
    _CV2_AVAILABLE = False

logger = logging.getLogger(__name__)

# Detection interval in seconds (reduce CPU usage vs. latency)
CAPTURE_INTERVAL: float = 1.5
# Minimum YOLOv8 confidence to trigger an alert
CONFIDENCE_THRESHOLD: float = 0.55
# COCO class ID for "cell phone" = 67
PHONE_CLASS_ID: int = 67


# ---------------------------------------------------------------------------
# YOLOv8 detector
# ---------------------------------------------------------------------------
class YoloPhoneDetector:
    """Loads a YOLOv8 nano model and checks frames for cell phones."""

    def __init__(self, model_path: str = "yolov8n.pt") -> None:
        if not _CV2_AVAILABLE:
            logger.warning("opencv-python not installed — YOLOv8 disabled")
            self._available = False
            return
        try:
            from ultralytics import YOLO  # type: ignore

            self._model = YOLO(model_path)
            logger.info("YOLOv8 model loaded from '%s'", model_path)
            self._available = True
        except Exception as exc:  # noqa: BLE001
            logger.warning("YOLOv8 unavailable: %s — falling back to MediaPipe", exc)
            self._available = False

    @property
    def available(self) -> bool:
        return self._available

    def detect_phone(self, frame) -> bool:
        """Return True if a phone is detected in *frame*."""
        if not self._available or not _CV2_AVAILABLE:
            return False
        results = self._model(frame, verbose=False)
        for result in results:
            for box in result.boxes:
                cls_id = int(box.cls[0])
                conf = float(box.conf[0])
                if cls_id == PHONE_CLASS_ID and conf >= CONFIDENCE_THRESHOLD:
                    logger.debug("Phone detected (conf=%.2f)", conf)
                    return True
        return False


# ---------------------------------------------------------------------------
# MediaPipe fallback — uses object detection with MobileNetV2-SSD
# ---------------------------------------------------------------------------
class MediaPipePhoneDetector:
    """Fallback detector using OpenCV DNN + a MobileNet SSD model."""

    # Public model URLs (downloaded once)
    _PROTO = "https://raw.githubusercontent.com/chuanqi305/MobileNet-SSD/master/deploy.prototxt"
    _MODEL = "https://github.com/chuanqi305/MobileNet-SSD/raw/master/mobilenet_iter_73000.caffemodel"
    # PASCAL VOC class 'mobilephone' doesn't exist; best proxy is searching for
    # rectangular hand-held objects.  We flag any object that overlaps with a
    # hand region as a potential phone.  This is intentionally conservative.

    def __init__(self) -> None:
        if not _CV2_AVAILABLE:
            self._available = False
            return
        try:
            import mediapipe as mp  # type: ignore  # noqa: F401

            self._mp_hands = mp.solutions.hands
            self._hands = self._mp_hands.Hands(
                static_image_mode=False,
                max_num_hands=2,
                min_detection_confidence=0.5,
            )
            self._available = True
            logger.info("MediaPipe hands model loaded (phone proxy)")
        except Exception as exc:  # noqa: BLE001
            logger.warning("MediaPipe unavailable: %s", exc)
            self._available = False

    @property
    def available(self) -> bool:
        return self._available

    def detect_phone(self, frame) -> bool:
        """
        Heuristic: if a hand is detected near the top half of the frame and
        a screen-like rectangle is visible, flag as phone.
        This is a conservative proxy — works best when real YOLOv8 is used.
        """
        if not self._available or not _CV2_AVAILABLE:
            return False
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        result = self._hands.process(rgb)
        if result.multi_hand_landmarks:
            # Check if any hand landmark is in the upper 60 % of the frame
            h, w, _ = frame.shape
            for hand_landmarks in result.multi_hand_landmarks:
                for lm in hand_landmarks.landmark:
                    if lm.y < 0.6:
                        # Look for a bright/screen-like rectangular region
                        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
                        edges = cv2.Canny(gray, 50, 150)
                        contours, _ = cv2.findContours(
                            edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
                        )
                        for cnt in contours:
                            x, y, cw, ch = cv2.boundingRect(cnt)
                            area = cw * ch
                            aspect = cw / ch if ch > 0 else 0
                            # Phone-like: tall narrow rectangle, reasonable area
                            if area > 5000 and 0.4 < aspect < 2.5:
                                return True
        return False


# ---------------------------------------------------------------------------
# Monitoring session
# ---------------------------------------------------------------------------
class CameraMonitorSession:
    """
    Manages a single student camera monitoring session.

    Parameters
    ----------
    quiz_id:        Identifier of the quiz being monitored
    student_id:     Identifier of the student
    on_phone_detected: Callback fired when a phone is detected.
                    Receives (quiz_id, student_id) as arguments.
    camera_index:   OpenCV camera index (0 = default camera)
    """

    def __init__(
        self,
        quiz_id: int,
        student_id: int,
        on_phone_detected: Callable[[int, int], None],
        camera_index: int = 0,
    ) -> None:
        self.quiz_id = quiz_id
        self.student_id = student_id
        self._callback = on_phone_detected
        self._camera_index = camera_index
        self._stop_event = threading.Event()
        self._thread: Optional[threading.Thread] = None

        # Prefer YOLOv8; fall back to MediaPipe
        self._yolo = YoloPhoneDetector()
        self._mediapipe = MediaPipePhoneDetector() if not self._yolo.available else None

    # ------------------------------------------------------------------
    @property
    def session_key(self) -> str:
        return f"{self.quiz_id}:{self.student_id}"

    # ------------------------------------------------------------------
    def start(self) -> bool:
        """
        Start the monitoring thread.
        Returns False if the camera cannot be opened.
        """
        if not _CV2_AVAILABLE:
            logger.warning("opencv not available — camera monitoring disabled")
            return True  # don't block quiz; just skip camera
        cap = cv2.VideoCapture(self._camera_index)
        if not cap.isOpened():
            logger.error(
                "Cannot open camera (index=%d) for session %s",
                self._camera_index,
                self.session_key,
            )
            cap.release()
            return False
        cap.release()

        self._stop_event.clear()
        self._thread = threading.Thread(
            target=self._run,
            name=f"monitor-{self.session_key}",
            daemon=True,
        )
        self._thread.start()
        logger.info("Monitoring started for session %s", self.session_key)
        return True

    # ------------------------------------------------------------------
    def stop(self) -> None:
        """Signal the monitoring thread to stop and wait for it."""
        self._stop_event.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=5.0)
        logger.info("Monitoring stopped for session %s", self.session_key)

    # ------------------------------------------------------------------
    def _run(self) -> None:
        if not _CV2_AVAILABLE:
            return
        cap = cv2.VideoCapture(self._camera_index)
        if not cap.isOpened():
            logger.error("Camera lost after session started — stopping")
            return

        try:
            while not self._stop_event.is_set():
                ret, frame = cap.read()
                if not ret:
                    logger.warning("Failed to read camera frame")
                    time.sleep(CAPTURE_INTERVAL)
                    continue

                if self._detect(frame):
                    logger.warning(
                        "Phone detected in session %s", self.session_key
                    )
                    self._callback(self.quiz_id, self.student_id)
                    # After detecting, stop monitoring (quiz will be ended)
                    break

                time.sleep(CAPTURE_INTERVAL)
        finally:
            cap.release()

    # ------------------------------------------------------------------
    def _detect(self, frame: np.ndarray) -> bool:
        if self._yolo.available:
            return self._yolo.detect_phone(frame)
        if self._mediapipe and self._mediapipe.available:
            return self._mediapipe.detect_phone(frame)
        # No detector available — cannot verify; do not falsely flag
        return False
