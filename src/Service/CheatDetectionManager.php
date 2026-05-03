<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CheatDetectionManager
 * ─────────────────────
 * Communicates with the Python anti-cheat FastAPI service and enforces
 * cheat penalties (score = 0, quiz ended) in the Symfony database.
 *
 * All outgoing requests to Python are signed with HMAC-SHA256.
 * All incoming requests from Python are verified with the same secret.
 */
final class CheatDetectionManager
{
    /**
     * Cheat types that immediately terminate the quiz with score 0.
     * Any type not in this list is logged but does not end the quiz on its own
     * (use the constant ZERO_SCORE_CHEATS to adjust).
     */
    public const ZERO_SCORE_CHEATS = [
        'phone_detected',
        'camera_denied',
        'tab_switch',
        'focus_lost',
        'screenshot',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
        private readonly string              $pythonServiceUrl,
        private readonly string              $sharedSecret,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Outbound: Symfony → Python service
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Tell the Python service to open the camera and start monitoring.
     *
     * @return bool  true on success, false if camera unavailable (handled by Python)
     */
    public function startCameraMonitoring(
        int    $quizAttemptId,
        int    $quizId,
        int    $studentId,
        string $sessionToken,
    ): bool {
        $payload = json_encode([
            'quiz_attempt_id' => $quizAttemptId,
            'quiz_id'         => $quizId,
            'student_id'      => $studentId,
            'session_token'   => $sessionToken,
        ], JSON_THROW_ON_ERROR);

        $response = $this->callPython('/start-monitoring', $payload);
        if ($response === null) {
            $this->logger->error('Python service unreachable — camera monitoring not started');
            return false;
        }

        $data = json_decode($response, true);
        if (!($data['ok'] ?? false)) {
            $this->logger->warning('Python service refused start: {msg}', ['msg' => $data['message'] ?? '?']);
        }

        return (bool) ($data['ok'] ?? false);
    }

    /**
     * Tell the Python service to stop monitoring for this student.
     */
    public function stopCameraMonitoring(int $quizAttemptId, int $studentId): void
    {
        $payload = json_encode([
            'quiz_attempt_id' => $quizAttemptId,
            'student_id'      => $studentId,
        ], JSON_THROW_ON_ERROR);

        $this->callPython('/stop-monitoring', $payload);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inbound: validates the HMAC signature sent by Python (or the JS layer)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Verify that the X-Anti-Cheat-Signature header matches the request body.
     *
     * @param string $body      Raw request body
     * @param string $signature Value of X-Anti-Cheat-Signature header
     */
    public function verifySignature(string $body, string $signature): bool
    {
        $expected = hash_hmac('sha256', $body, $this->sharedSecret);
        return hash_equals($expected, $signature);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Database helpers — called by CheatDetectionController
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Record a cheat event and, if the cheat type warrants it, set score = 0
     * and end the quiz attempt.
     *
     * @param \Doctrine\DBAL\Connection $conn
     * @param int    $attemptId   quiz_attempts.id
     * @param string $cheatType   One of the ZERO_SCORE_CHEATS constants
     * @param string $source      'python_service' | 'js_client'
     *
     * @return array{terminated: bool, attempt: array<string,mixed>|false}
     */
    public function handleCheatEvent(
        \Doctrine\DBAL\Connection $conn,
        int                       $attemptId,
        string                    $cheatType,
        string                    $source = 'js_client',
    ): array {
        // Sanitise cheat type
        $cheatType = preg_replace('/[^a-z_]/', '', strtolower($cheatType));

        // Fetch the attempt (including cheat_flags)
        $attempt = $conn->fetchAssociative(
            'SELECT id, quiz_id, student_user_id, finished_at, cheat_flags
             FROM quiz_attempts
             WHERE id = ?',
            [$attemptId]
        );

        if (!$attempt) {
            $this->logger->warning('Cheat event for unknown attempt {id}', ['id' => $attemptId]);
            return ['terminated' => false, 'attempt' => false];
        }

        // Decode existing flags
        $flags = [];
        if ($attempt['cheat_flags']) {
            $decoded = json_decode($attempt['cheat_flags'], true);
            if (is_array($decoded)) {
                $flags = $decoded;
            }
        }

        // Append new event
        $flags[] = [
            'type'      => $cheatType,
            'source'    => $source,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $shouldTerminate = in_array($cheatType, self::ZERO_SCORE_CHEATS, true)
            && !$attempt['finished_at'];

        if ($shouldTerminate) {
            $conn->executeStatement(
                'UPDATE quiz_attempts
                 SET cheat_flags   = ?,
                     earned_points = 0,
                     score_percent = 0.00,
                     level_result  = ?,
                     cheat_ended   = 1,
                     finished_at   = NOW()
                 WHERE id = ? AND finished_at IS NULL',
                [json_encode($flags, JSON_THROW_ON_ERROR), 'CHEATER', $attemptId]
            );
            $this->logger->info(
                'Quiz attempt {id} terminated for cheating ({type})',
                ['id' => $attemptId, 'type' => $cheatType]
            );
        } else {
            // Just update the flags log
            $conn->executeStatement(
                'UPDATE quiz_attempts SET cheat_flags = ? WHERE id = ?',
                [json_encode($flags, JSON_THROW_ON_ERROR), $attemptId]
            );
        }

        return ['terminated' => $shouldTerminate, 'attempt' => $attempt];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST JSON to the Python service, signed with HMAC-SHA256.
     * Returns the response body as a string, or null on network failure.
     */
    private function callPython(string $path, string $jsonBody): ?string
    {
        $signature = hash_hmac('sha256', $jsonBody, $this->sharedSecret);
        $url       = rtrim($this->pythonServiceUrl, '/') . $path;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type'             => 'application/json',
                    'X-Anti-Cheat-Signature'   => $signature,
                ],
                'body'    => $jsonBody,
                'timeout' => 5.0,
            ]);

            return $response->getContent(throw: false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Python service transport error: {msg}', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
