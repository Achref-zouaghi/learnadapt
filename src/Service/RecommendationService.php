<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Smart Course Recommendation Service
 *
 * Hybrid scoring algorithm:
 *   score = (0.5 × similarity) + (0.3 × popularity) + (0.2 × history)
 *
 * Used by ApiRecommendationController for the recommendation API endpoints.
 */
class RecommendationService
{
    public function __construct(
        private readonly Connection $conn,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/recommendations/{userId}
     * Returns personalised course recommendations with hybrid score.
     */
    public function getRecommendations(int $userId, int $limit = 6): array
    {
        $userProfile   = $this->getUserProfile($userId);
        $allCourses    = $this->getCoursesNotEnrolled($userId);
        $popularityMap = $this->buildPopularityMap();
        $historyMap    = $this->buildHistoryMap($userId);

        $scored = [];
        foreach ($allCourses as $course) {
            $courseId = (int) $course['id'];

            // 1. Content similarity (same level + same module)
            $similarity = $this->computeSimilarity($course, $userProfile);

            // 2. Popularity score (0-1, normalised)
            $popularity = $popularityMap[$courseId] ?? 0.0;

            // 3. History score — user visited this module/level before
            $history = $historyMap[$courseId] ?? 0.0;

            // Hybrid score
            $score = (0.5 * $similarity) + (0.3 * $popularity) + (0.2 * $history);

            $scored[] = array_merge($course, [
                'score'           => round($score, 4),
                'score_similarity'=> round($similarity, 4),
                'score_popularity'=> round($popularity, 4),
                'score_history'   => round($history, 4),
            ]);
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * POST /api/user/activity
     * Records a user activity event (view, search, enroll).
     */
    public function recordActivity(int $userId, string $activityType, ?int $courseId, ?string $searchQuery): void
    {
        $this->conn->executeStatement(
            'INSERT INTO user_activity (user_id, activity_type, course_id, search_query, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$userId, $activityType, $courseId, $searchQuery]
        );
    }

    /**
     * GET /api/courses/trending
     * Returns courses ranked by enrolments + rating + recent activity.
     */
    public function getTrending(int $limit = 8): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT
                c.id, c.title, c.level, c.description,
                m.name AS module_name,
                COUNT(DISTINCT cp.user_id)                                    AS enrolled_count,
                COALESCE(AVG(cr.rating), 0)                                   AS avg_rating,
                COUNT(DISTINCT cr.id)                                         AS rating_count,
                COUNT(DISTINCT ua.id)                                         AS recent_views,
                (
                    COUNT(DISTINCT cp.user_id) * 0.5
                  + COALESCE(AVG(cr.rating), 0) * 0.3
                  + COUNT(DISTINCT ua.id) * 0.2
                )                                                             AS trend_score
             FROM courses c
             LEFT JOIN modules m          ON m.id = c.module_id
             LEFT JOIN course_progress cp ON cp.course_id = c.id
             LEFT JOIN course_ratings cr  ON cr.course_id = c.id
             LEFT JOIN user_activity ua   ON ua.course_id = c.id
                                         AND ua.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY c.id, c.title, c.level, c.description, m.name
             ORDER BY trend_score DESC
             LIMIT ' . (int)$limit
        );
    }

    /**
     * GET /api/courses/search?q=...
     * Smart keyword search with relevance ranking + suggestions.
     */
    public function search(string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';

        $results = $this->conn->fetchAllAssociative(
            'SELECT
                c.id, c.title, c.level, c.description,
                m.name AS module_name,
                COUNT(DISTINCT cp.user_id)      AS enrolled_count,
                COALESCE(AVG(cr.rating), 0)     AS avg_rating,
                CASE
                    WHEN c.title LIKE ?          THEN 3
                    WHEN m.name  LIKE ?          THEN 2
                    WHEN c.description LIKE ?    THEN 1
                    ELSE 0
                END                             AS relevance
             FROM courses c
             LEFT JOIN modules m          ON m.id = c.module_id
             LEFT JOIN course_progress cp ON cp.course_id = c.id
             LEFT JOIN course_ratings cr  ON cr.course_id = c.id
             WHERE c.title LIKE ? OR m.name LIKE ? OR c.description LIKE ?
             GROUP BY c.id, c.title, c.level, c.description, m.name
             ORDER BY relevance DESC, enrolled_count DESC
             LIMIT ' . (int)$limit,
            [$q, $q, $q, $q, $q, $q]
        );

        // Keyword suggestions: other popular modules matching query
        $suggestions = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT m.name AS suggestion
             FROM modules m
             JOIN courses c ON c.module_id = m.id
             WHERE m.name LIKE ?
             LIMIT 5',
            [$q]
        );

        return [
            'results'     => $results,
            'suggestions' => array_column($suggestions, 'suggestion'),
            'query'       => $query,
            'total'       => count($results),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a profile of the user: preferred level, preferred modules.
     */
    private function getUserProfile(int $userId): array
    {
        // Level from student_levels table
        $levelRow = $this->conn->fetchAssociative(
            'SELECT current_level FROM student_levels WHERE student_user_id = ? ORDER BY updated_at DESC LIMIT 1',
            [$userId]
        );
        $preferredLevel = $levelRow['current_level'] ?? null;

        // Modules the user has engaged with most
        $moduleRows = $this->conn->fetchAllAssociative(
            'SELECT c.module_id, COUNT(*) AS cnt
             FROM course_progress cp
             JOIN courses c ON c.id = cp.course_id
             WHERE cp.user_id = ?
             GROUP BY c.module_id
             ORDER BY cnt DESC
             LIMIT 3',
            [$userId]
        );
        $preferredModules = array_column($moduleRows, 'module_id');

        // Levels of courses the user already engaged with
        $levelRows = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT c.level
             FROM course_progress cp
             JOIN courses c ON c.id = cp.course_id
             WHERE cp.user_id = ?',
            [$userId]
        );
        $engagedLevels = array_column($levelRows, 'level');

        return [
            'preferred_level'   => $preferredLevel,
            'preferred_modules' => $preferredModules,
            'engaged_levels'    => $engagedLevels,
        ];
    }

    /**
     * Get all courses with enrolment status for the user.
     * We rank ALL courses (including enrolled ones) so the panel is never empty.
     */
    private function getCoursesNotEnrolled(int $userId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT c.id, c.title, c.level, c.description, c.module_id,
                    m.name AS module_name,
                    COALESCE(cp.progress_percent, 0) AS progress_percent,
                    CASE WHEN cp.course_id IS NOT NULL THEN 1 ELSE 0 END AS is_enrolled
             FROM courses c
             LEFT JOIN modules m          ON m.id = c.module_id
             LEFT JOIN course_progress cp ON cp.course_id = c.id AND cp.user_id = ?
             ORDER BY c.created_at DESC',
            [$userId]
        );
    }

    /**
     * Popularity map: courseId → normalised score (0–1) based on enrolments + rating.
     */
    private function buildPopularityMap(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.id,
                    COUNT(DISTINCT cp.user_id)  AS enrolled,
                    COALESCE(AVG(cr.rating), 0) AS avg_rating
             FROM courses c
             LEFT JOIN course_progress cp ON cp.course_id = c.id
             LEFT JOIN course_ratings cr  ON cr.course_id = c.id
             GROUP BY c.id'
        );

        if (empty($rows)) {
            return [];
        }

        // Raw score = enrolled * 0.6 + avg_rating * 0.4
        $rawScores = [];
        foreach ($rows as $row) {
            $rawScores[(int)$row['id']] = (float)$row['enrolled'] * 0.6 + (float)$row['avg_rating'] * 0.4;
        }

        $max = max($rawScores) ?: 1;

        return array_map(fn($v) => $v / $max, $rawScores);
    }

    /**
     * History map: courseId → affinity score based on same module/level as viewed courses.
     */
    private function buildHistoryMap(int $userId): array
    {
        $viewed = $this->conn->fetchAllAssociative(
            'SELECT c.module_id, c.level, COUNT(*) AS w
             FROM user_activity ua
             JOIN courses c ON c.id = ua.course_id
             WHERE ua.user_id = ? AND ua.course_id IS NOT NULL
             GROUP BY c.module_id, c.level',
            [$userId]
        );

        if (empty($viewed)) {
            return [];
        }

        // Build a weight map [module_id][level] => weight
        $weights = [];
        foreach ($viewed as $v) {
            $weights[$v['module_id']][$v['level']] = (int)$v['w'];
        }

        $all = $this->conn->fetchAllAssociative('SELECT id, module_id, level FROM courses');

        $scores = [];
        foreach ($all as $c) {
            $w = $weights[$c['module_id']][$c['level']] ?? 0;
            $scores[(int)$c['id']] = (float)$w;
        }

        $max = max($scores) ?: 1;
        return array_map(fn($v) => $v / $max, $scores);
    }

    /**
     * Similarity score between a candidate course and the user profile.
     */
    private function computeSimilarity(array $course, array $profile): float
    {
        $score = 0.0;

        // Same level as preferred level
        if ($profile['preferred_level'] && $course['level'] === $profile['preferred_level']) {
            $score += 0.5;
        }
        // Same level as any engaged level
        if (in_array($course['level'], $profile['engaged_levels'], true)) {
            $score += 0.25;
        }
        // Same module as preferred modules
        if (in_array((int)$course['module_id'], $profile['preferred_modules'], true)) {
            $score += 0.25;
        }

        return min($score, 1.0);
    }
}
