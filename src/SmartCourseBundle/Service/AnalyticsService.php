<?php

namespace App\SmartCourseBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * AnalyticsService — SmartCourseBundle
 *
 * Provides course analytics:
 *   - Per-course stats (views, enrolments, completion rate)
 *   - Global platform stats
 *   - Top courses ranking
 *   - Per-user engagement metrics
 *
 * Usage:
 *   $stats   = $analyticsService->getCourseStats($courseId);
 *   $global  = $analyticsService->getGlobalStats();
 *   $top     = $analyticsService->getTopCourses(5);
 *   $engaged = $analyticsService->getUserEngagement($userId);
 */
class AnalyticsService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly bool $enabled,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-course
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns detailed analytics for a single course.
     *
     * @return array{
     *   course_id: int,
     *   title: string,
     *   level: string,
     *   total_enrolments: int,
     *   completed_count: int,
     *   completion_rate: float,
     *   avg_progress: float,
     *   avg_rating: float,
     *   rating_count: int,
     *   total_views: int,
     *   recent_views_7d: int,
     * }
     */
    public function getCourseStats(int $courseId): array
    {
        if (!$this->enabled) {
            return ['error' => 'Analytics disabled'];
        }

        $base = $this->conn->fetchAssociative(
            'SELECT
                c.id   AS course_id,
                c.title,
                c.level,
                COUNT(DISTINCT cp.user_id)                               AS total_enrolments,
                SUM(cp.progress_percent = 100)                           AS completed_count,
                ROUND(AVG(cp.progress_percent), 1)                       AS avg_progress,
                COALESCE(AVG(cr.rating), 0)                              AS avg_rating,
                COUNT(DISTINCT cr.id)                                    AS rating_count
             FROM courses c
             LEFT JOIN course_progress cp ON cp.course_id = c.id
             LEFT JOIN course_ratings cr  ON cr.course_id = c.id
             WHERE c.id = ?
             GROUP BY c.id, c.title, c.level',
            [$courseId]
        ) ?: [];

        if (empty($base)) {
            return ['error' => 'Course not found'];
        }

        $views = $this->conn->fetchAssociative(
            'SELECT
                COUNT(*)                                                AS total_views,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))     AS recent_views_7d
             FROM user_activity
             WHERE course_id = ? AND activity_type = ?',
            [$courseId, 'view']
        ) ?: ['total_views' => 0, 'recent_views_7d' => 0];

        $total       = (int) ($base['total_enrolments'] ?? 0);
        $completed   = (int) ($base['completed_count'] ?? 0);
        $rate        = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        return array_merge($base, $views, [
            'completion_rate' => $rate,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Platform-wide
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Global platform analytics snapshot.
     */
    public function getGlobalStats(): array
    {
        if (!$this->enabled) {
            return ['error' => 'Analytics disabled'];
        }

        return $this->conn->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM courses)                          AS total_courses,
                (SELECT COUNT(DISTINCT user_id) FROM course_progress)  AS active_learners,
                (SELECT COUNT(*) FROM course_progress
                 WHERE progress_percent = 100)                          AS completions,
                (SELECT ROUND(AVG(rating),2) FROM course_ratings)       AS platform_avg_rating,
                (SELECT COUNT(*) FROM user_activity
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS activity_7d'
        ) ?: [];
    }

    /**
     * Top N courses by composite score: enrolments + rating + recent activity.
     */
    public function getTopCourses(int $limit = 5): array
    {
        if (!$this->enabled) {
            return [];
        }

        return $this->conn->fetchAllAssociative(
            'SELECT
                c.id, c.title, c.level,
                m.name                                                   AS module_name,
                COUNT(DISTINCT cp.user_id)                               AS enrolments,
                COALESCE(AVG(cr.rating), 0)                              AS avg_rating,
                COUNT(DISTINCT ua.id)                                    AS recent_views,
                ROUND(
                    COUNT(DISTINCT cp.user_id) * 0.5
                  + COALESCE(AVG(cr.rating), 0) * 0.3
                  + COUNT(DISTINCT ua.id) * 0.2
                , 2)                                                     AS analytics_score
             FROM courses c
             LEFT JOIN modules m          ON m.id = c.module_id
             LEFT JOIN course_progress cp ON cp.course_id = c.id
             LEFT JOIN course_ratings cr  ON cr.course_id = c.id
             LEFT JOIN user_activity ua   ON ua.course_id = c.id
                                         AND ua.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY c.id, c.title, c.level, m.name
             ORDER BY analytics_score DESC
             LIMIT ' . (int)$limit
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-user engagement
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * How engaged is a specific user on the platform?
     */
    public function getUserEngagement(int $userId): array
    {
        if (!$this->enabled) {
            return ['error' => 'Analytics disabled'];
        }

        $progress = $this->conn->fetchAssociative(
            'SELECT
                COUNT(*)                          AS courses_enrolled,
                SUM(progress_percent = 100)       AS courses_completed,
                ROUND(AVG(progress_percent), 1)   AS avg_progress,
                SUM(xp_earned)                    AS total_xp
             FROM course_progress
             WHERE user_id = ?',
            [$userId]
        ) ?: [];

        $activity = $this->conn->fetchAssociative(
            'SELECT
                COUNT(*) AS total_events,
                MAX(created_at) AS last_seen
             FROM user_activity
             WHERE user_id = ?',
            [$userId]
        ) ?: [];

        return array_merge($progress, $activity, ['user_id' => $userId]);
    }
}
