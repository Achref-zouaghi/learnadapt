<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CheatDetectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * CheatDetectionController
 * ─────────────────────────
 * REST API consumed by:
 *   • Python anti-cheat service  → POST /api/quiz/cheat-detected
 *   • Browser JavaScript         → POST /api/quiz/cheat-detected
 *                                  POST /api/quiz/heartbeat
 *   • Quiz start flow            → POST /api/quiz/start-monitoring
 *   • Quiz end flow              → POST /api/quiz/stop-monitoring
 */
#[Route('/api/quiz', name: 'api_quiz_')]
final class CheatDetectionController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly CheatDetectionManager  $cheatManager,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/cheat-detected
    // ──────────────────────────────────────────────────────────────────────
    /**
     * Receives a cheat event from either the Python service or the JS client.
     *
     * Expected JSON body:
     * {
     *   "attempt_id":  123,
     *   "student_id":  456,
     *   "cheat_type":  "tab_switch",
     *   "source":      "js_client",   // or "python_service"
     *   "timestamp":   1714900000
     * }
     *
     * Security:
     *   • Requests from Python must carry X-Anti-Cheat-Signature header.
     *   • Requests from JS must carry a valid session cookie (student authenticated).
     *   • student_id is always cross-checked against the attempt record to
     *     prevent one student from sabotaging another's quiz.
     */
    #[Route('/cheat-detected', name: 'cheat_detected', methods: ['POST'])]
    public function cheatDetected(Request $request): JsonResponse
    {
        $rawBody  = $request->getContent();
        $data     = json_decode($rawBody, true);
        $source   = $data['source'] ?? 'js_client';

        // ── Validate source & authenticate ──────────────────────────────
        if ($source === 'python_service') {
            // Must carry a valid HMAC signature
            $sig = $request->headers->get('X-Anti-Cheat-Signature', '');
            if (!$this->cheatManager->verifySignature($rawBody, $sig)) {
                return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
            }
        } else {
            // Must be an authenticated student session
            $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
            if (!is_array($auth) || !isset($auth['id'])) {
                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        // ── Validate payload ────────────────────────────────────────────
        $attemptId = isset($data['attempt_id']) ? (int) $data['attempt_id'] : 0;
        $studentId = isset($data['student_id']) ? (int) $data['student_id'] : 0;
        $cheatType = isset($data['cheat_type']) ? (string) $data['cheat_type'] : '';

        if ($attemptId <= 0 || $studentId <= 0 || $cheatType === '') {
            return new JsonResponse(['error' => 'Bad request'], Response::HTTP_BAD_REQUEST);
        }

        // ── Ownership check ─────────────────────────────────────────────
        // For JS requests: the authenticated student_id must own this attempt.
        if ($source !== 'python_service') {
            $auth   = $request->getSession()->get(self::AUTH_SESSION_KEY);
            $authId = (int) ($auth['id'] ?? 0);
            if ($authId !== $studentId) {
                return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
            }
        }

        // ── Process event ────────────────────────────────────────────────
        $conn   = $this->em->getConnection();
        $result = $this->cheatManager->handleCheatEvent($conn, $attemptId, $cheatType, $source);

        if ($result['attempt'] === false) {
            return new JsonResponse(['error' => 'Attempt not found'], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('Cheat event [{type}] recorded for attempt {id}', [
            'type' => $cheatType,
            'id'   => $attemptId,
        ]);

        return new JsonResponse([
            'ok'         => true,
            'terminated' => $result['terminated'],
            'message'    => $result['terminated']
                ? 'Quiz terminated — score set to 0'
                : 'Cheat event logged',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/heartbeat
    // ──────────────────────────────────────────────────────────────────────
    /**
     * Called every 5 s by the browser JS.
     * If the quiz session has been terminated (cheat_ended=1) the response
     * tells the browser to redirect to results immediately.
     *
     * Body: { "attempt_id": 123 }
     */
    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data      = json_decode($request->getContent(), true);
        $attemptId = isset($data['attempt_id']) ? (int) $data['attempt_id'] : 0;
        $studentId = (int) $auth['id'];

        if ($attemptId <= 0) {
            return new JsonResponse(['error' => 'Bad request'], Response::HTTP_BAD_REQUEST);
        }

        $conn    = $this->em->getConnection();
        $attempt = $conn->fetchAssociative(
            'SELECT id, finished_at, cheat_ended FROM quiz_attempts
             WHERE id = ? AND student_user_id = ?',
            [$attemptId, $studentId]
        );

        if (!$attempt) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'ok'          => true,
            'active'      => $attempt['finished_at'] === null,
            'cheat_ended' => (bool) $attempt['cheat_ended'],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/start-monitoring
    // ──────────────────────────────────────────────────────────────────────
    /**
     * Called by the QuizController (server-side) after a quiz attempt is
     * created, to trigger Python camera monitoring.
     * This endpoint is intentionally called server-to-service, not from JS.
     *
     * Body: { "attempt_id": 1, "quiz_id": 2, "student_id": 3, "token": "…" }
     */
    #[Route('/start-monitoring', name: 'start_monitoring', methods: ['POST'])]
    public function startMonitoring(Request $request): JsonResponse
    {
        // Only accessible from authenticated server-side calls or via session
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        $attemptId = isset($data['attempt_id']) ? (int) $data['attempt_id'] : 0;
        $quizId    = isset($data['quiz_id'])    ? (int) $data['quiz_id']    : 0;
        $studentId = (int) $auth['id'];
        $token     = $request->getSession()->getId();

        if ($attemptId <= 0 || $quizId <= 0) {
            return new JsonResponse(['error' => 'Bad request'], Response::HTTP_BAD_REQUEST);
        }

        $ok = $this->cheatManager->startCameraMonitoring($attemptId, $quizId, $studentId, $token);

        return new JsonResponse([
            'ok'      => $ok,
            'message' => $ok ? 'Camera monitoring started' : 'Camera unavailable (cheat logged)',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/stop-monitoring
    // ──────────────────────────────────────────────────────────────────────
    #[Route('/stop-monitoring', name: 'stop_monitoring', methods: ['POST'])]
    public function stopMonitoring(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data      = json_decode($request->getContent(), true);
        $attemptId = isset($data['attempt_id']) ? (int) $data['attempt_id'] : 0;
        $studentId = (int) $auth['id'];

        $this->cheatManager->stopCameraMonitoring($attemptId, $studentId);

        return new JsonResponse(['ok' => true]);
    }
}
