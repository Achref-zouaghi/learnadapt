<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Gathers student performance data from the DB and forwards it to the
 * Python AI service for profiling and adaptive recommendations.
 */
class AdaptiveLearningService
{
    private const PYTHON_URL        = 'http://127.0.0.1:8765';
    private const ENDPOINT          = '/analyze-student';
    private const REQUEST_TIMEOUT   = 5; // seconds

    public function __construct(
        private readonly Connection      $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the AI profile for the given student, or null on failure.
     *
     * @return array{
     *   engagement_score: float,
     *   learning_speed: string,
     *   risk_level: string,
     *   risk_score: float,
     *   success_probability: float,
     *   student_profile: string,
     *   recommended_difficulty: string,
     *   strengths: list<string>,
     *   improvement_areas: list<string>,
     *   recommendations: list<string>,
     *   adaptive_message: string,
     * }|null
     */
    public function getStudentProfile(int $studentId): ?array
    {
        $data = $this->collectStudentData($studentId);
        return $this->callPythonService($studentId, $data);
    }

    // ─────────────────────────────────────────────────────────────────────
    // DB data collection
    // ─────────────────────────────────────────────────────────────────────

    private function collectStudentData(int $studentId): array
    {
        // Quiz scores
        $quizRows = $this->connection->executeQuery(
            'SELECT score_percent FROM quiz_attempts
             WHERE student_user_id = :sid AND score_percent IS NOT NULL
             ORDER BY finished_at DESC LIMIT 30',
            ['sid' => $studentId]
        )->fetchAllAssociative();
        $quizScores = array_column($quizRows, 'score_percent');

        // Cheat count
        $cheatCount = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM quiz_attempts
             WHERE student_user_id = :sid AND cheat_ended = 1',
            ['sid' => $studentId]
        )->fetchOne();

        // Course progress
        $progressRows = $this->connection->executeQuery(
            'SELECT progress_percent FROM course_progress
             WHERE user_id = :sid AND progress_percent IS NOT NULL',
            ['sid' => $studentId]
        )->fetchAllAssociative();
        $courseProgress = array_column($progressRows, 'progress_percent');

        // Completed & total enrolled
        $completedCourses = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM course_progress
             WHERE user_id = :sid AND completed_at IS NOT NULL',
            ['sid' => $studentId]
        )->fetchOne();

        $totalEnrolled = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM course_progress WHERE user_id = :sid',
            ['sid' => $studentId]
        )->fetchOne();

        // Streak
        $streakRow = $this->connection->executeQuery(
            'SELECT current_streak FROM user_streaks WHERE user_id = :sid',
            ['sid' => $studentId]
        )->fetchAssociative();
        $streakDays = $streakRow ? (int) $streakRow['current_streak'] : 0;

        return [
            'quiz_scores'       => array_map('floatval', $quizScores),
            'course_progress'   => array_map('floatval', $courseProgress),
            'completed_courses' => $completedCourses,
            'total_enrolled'    => $totalEnrolled,
            'streak_days'       => $streakDays,
            'cheat_count'       => $cheatCount,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Python service call (HMAC-signed)
    // ─────────────────────────────────────────────────────────────────────

    private function callPythonService(int $studentId, array $data): ?array
    {
        $secret = (string) ($_ENV['ANTI_CHEAT_SECRET'] ?? getenv('ANTI_CHEAT_SECRET') ?? 'dev_secret_change_in_production');

        $payload = json_encode([
            'student_id'        => $studentId,
            'quiz_scores'       => $data['quiz_scores'],
            'course_progress'   => $data['course_progress'],
            'completed_courses' => $data['completed_courses'],
            'total_enrolled'    => $data['total_enrolled'],
            'streak_days'       => $data['streak_days'],
            'cheat_count'       => $data['cheat_count'],
        ]);

        $signature = hash_hmac('sha256', $payload, $secret);
        $url       = self::PYTHON_URL . self::ENDPOINT;

        $ctx = stream_context_create([
            'http' => [
                'method'         => 'POST',
                'header'         => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Anti-Cheat-Signature: ' . $signature,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'        => $payload,
                'timeout'        => self::REQUEST_TIMEOUT,
                'ignore_errors'  => true,
            ],
        ]);

        try {
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                $this->logger->warning('AdaptiveLearning: Python service unreachable at ' . $url);
                return null;
            }
            $result = json_decode($raw, true);
            if (!is_array($result) || isset($result['detail'])) {
                $this->logger->warning('AdaptiveLearning: Unexpected Python response', ['raw' => $raw]);
                return null;
            }
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('AdaptiveLearning: ' . $e->getMessage());
            return null;
        }
    }
}
