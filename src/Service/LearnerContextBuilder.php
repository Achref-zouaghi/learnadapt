<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * LearnAdapt Mind — Cognitive Learning Report Engine
 *
 * Computes all learner metrics from raw DB data.
 * These numbers are passed to Groq to generate the AI-written narrative.
 *
 * Metrics computed:
 *   - Peak performance window (hour with most Pomodoro activity + quiz multiplier)
 *   - Abandoned courses (stalled 20–75%, inactive >7 days)
 *   - Learning velocity change (this week vs last week)
 *   - Knowledge gaps (untouched modules + low-score quizzes)
 *   - Micro-goal (next best course action)
 *   - Focus minutes, active days, completed courses, quiz average, total XP
 *   - Health score (0–100 composite)
 */
class LearnerContextBuilder
{
    public function __construct(private readonly Connection $conn) {}

    // ── PUBLIC API ──────────────────────────────────────────────────────────

    /**
     * Build the complete metrics array for a user's current week.
     * Every metric block is computed independently — a failure in one
     * does NOT break the rest of the report.
     */
    public function buildMetrics(int $userId): array
    {
        $metrics = [
            'user_name'  => $this->getUserName($userId),
            'week_start' => date('Y-m-d', strtotime('monday this week')),
            'week_end'   => date('Y-m-d', strtotime('sunday this week')),
        ];

        try { $metrics['peak_window']            = $this->computePeakWindow($userId); }
        catch (\Throwable) { $metrics['peak_window'] = ['label' => 'No focus data yet', 'multiplier' => null, 'best_hour' => null]; }

        try { $metrics['abandoned_courses']       = $this->getAbandonedCourses($userId); }
        catch (\Throwable) { $metrics['abandoned_courses'] = []; }

        try { $metrics['learning_velocity']       = $this->computeLearningVelocity($userId); }
        catch (\Throwable) { $metrics['learning_velocity'] = ['this_week' => 0, 'last_week' => 0, 'change_pct' => 0]; }

        try { $metrics['knowledge_gaps']          = $this->detectKnowledgeGaps($userId); }
        catch (\Throwable) { $metrics['knowledge_gaps'] = []; }

        try { $metrics['micro_goal']              = $this->computeMicroGoal($userId); }
        catch (\Throwable) { $metrics['micro_goal'] = null; }

        try { $metrics['focus_minutes_week']      = $this->getFocusMinutesThisWeek($userId); }
        catch (\Throwable) { $metrics['focus_minutes_week'] = 0; }

        try { $metrics['courses_completed_week']  = $this->getCoursesCompletedThisWeek($userId); }
        catch (\Throwable) { $metrics['courses_completed_week'] = 0; }

        try { $metrics['quiz_avg_week']           = $this->getQuizAvgThisWeek($userId); }
        catch (\Throwable) { $metrics['quiz_avg_week'] = null; }

        try { $metrics['active_days_week']        = $this->getActiveDaysThisWeek($userId); }
        catch (\Throwable) { $metrics['active_days_week'] = 0; }

        try { $metrics['total_xp']                = $this->getTotalXp($userId); }
        catch (\Throwable) { $metrics['total_xp'] = 0; }

        try { $metrics['total_courses_enrolled']  = $this->getTotalCoursesEnrolled($userId); }
        catch (\Throwable) { $metrics['total_courses_enrolled'] = 0; }

        try { $metrics['total_courses_completed'] = $this->getTotalCoursesCompleted($userId); }
        catch (\Throwable) { $metrics['total_courses_completed'] = 0; }

        // Health score computed last (uses all other metrics)
        try { $metrics['health_score'] = $this->computeHealthScore($metrics); }
        catch (\Throwable) { $metrics['health_score'] = 50; }

        return $metrics;
    }

    /**
     * Build the Groq system+user prompt from computed metrics.
     */
    public function buildGroqPrompt(array $metrics): string
    {
        $name      = $metrics['user_name'] ?? 'Learner';
        $weekStart = date('M d', strtotime($metrics['week_start']));
        $weekEnd   = date('M d, Y', strtotime($metrics['week_end']));

        // Peak window
        $peakLabel  = $metrics['peak_window']['label'] ?? 'unknown';
        $multiplier = $metrics['peak_window']['multiplier'] ?? null;
        $multStr    = $multiplier ? "{$multiplier}× better quiz performance during this window" : 'no quiz correlation data';

        // Velocity
        $velChange  = $metrics['learning_velocity']['change_pct'] ?? 0;
        $velStr     = $velChange >= 0 ? "+{$velChange}%" : "{$velChange}%";

        // Abandoned
        $abandoned    = $metrics['abandoned_courses'] ?? [];
        $abandonedStr = empty($abandoned)
            ? 'None — great consistency!'
            : implode(', ', array_map(
                fn($c) => "\"{$c['title']}\" ({$c['progress_percent']}% done, stalled since " . date('M d', strtotime($c['last_accessed'])) . ")",
                array_slice($abandoned, 0, 3)
              ));

        $patternStr = 'None detected';
        if (count($abandoned) >= 2) {
            $avgPct     = round(array_sum(array_column($abandoned, 'progress_percent')) / count($abandoned));
            $patternStr = "Consistent abandonment around {$avgPct}% completion — likely a difficulty spike or motivation wall at this stage";
        } elseif (count($abandoned) === 1) {
            $patternStr = 'Isolated case — monitor next week';
        }

        // Knowledge gaps
        $gaps    = $metrics['knowledge_gaps'] ?? [];
        $gapsStr = empty($gaps)
            ? 'No critical gaps detected — well-rounded coverage!'
            : implode("\n", array_map(
                fn($g) => "- {$g['subject']}: {$g['detail']}",
                array_slice($gaps, 0, 4)
              ));

        // Micro-goal
        $goal        = $metrics['micro_goal'] ?? null;
        $microGoalStr = $goal
            ? "\"{$goal['title']}\" — currently {$goal['progress_percent']}% complete, needs ~{$goal['sessions_needed']} focused Pomodoro session(s) to finish"
            : 'Start a new course that matches your current knowledge level';

        // Quiz avg
        $quizAvg    = $metrics['quiz_avg_week'] ?? null;
        $quizAvgStr = $quizAvg !== null ? round($quizAvg, 1) . '%' : 'No quizzes taken this week';

        return <<<PROMPT
You are LearnAdapt Mind, the proprietary AI intelligence engine of the LearnAdapt platform.
Write a personalized weekly cognitive learning report for {$name} covering {$weekStart}–{$weekEnd}.

Rules:
- Use the EXACT numbers provided below. Do not invent statistics.
- Write in second person (you/your), directly to {$name}.
- Start with their name. Use — bullet points throughout.
- Be data-driven, honest, and encouraging. Do not be generic.
- End with a specific, actionable micro-recommendation.
- Maximum 380 words. No markdown headers — just clean bullet points.

═══ LEARNER DATA FOR {$name} ═══

FOCUS & ACTIVITY:
- Total focus time this week: {$metrics['focus_minutes_week']} minutes
- Active study days: {$metrics['active_days_week']} out of 7
- Courses completed this week: {$metrics['courses_completed_week']}
- Quiz average this week: {$quizAvgStr}
- All-time XP earned: {$metrics['total_xp']}
- Total courses enrolled: {$metrics['total_courses_enrolled']} ({$metrics['total_courses_completed']} completed)

PEAK PERFORMANCE WINDOW:
- Best hour for studying: {$peakLabel}
- Quiz score multiplier: {$multStr}

LEARNING VELOCITY:
- Activity change vs last week: {$velStr}

PROBLEM PATTERNS:
- Stalled courses (20–75% done, inactive >7 days): {$abandonedStr}
- Pattern analysis: {$patternStr}

KNOWLEDGE GAPS:
{$gapsStr}

NEXT BEST ACTION:
- Recommended micro-goal: {$microGoalStr}

Write the full report now for {$name}:
PROMPT;
    }

    // ── PRIVATE METRIC METHODS ──────────────────────────────────────────────

    private function getUserName(int $userId): string
    {
        return (string) ($this->conn->fetchOne('SELECT full_name FROM users WHERE id = ?', [$userId]) ?? 'Learner');
    }

    /**
     * Find the 2-hour window with the most Pomodoro activity.
     * Also computes whether quiz scores are higher on peak-window days.
     */
    private function computePeakWindow(int $userId): array
    {
        $topHour = $this->conn->fetchAssociative(
            "SELECT HOUR(ps.started_at) AS hour, SUM(ps.work_minutes * ps.cycles) AS minutes
             FROM pomodoro_sessions ps
             JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?)
               AND ps.started_at IS NOT NULL
             GROUP BY HOUR(ps.started_at)
             ORDER BY minutes DESC
             LIMIT 1",
            [$userId, $userId]
        );

        if (!$topHour) {
            return ['label' => 'No focus data yet', 'multiplier' => null, 'best_hour' => null];
        }

        $bestHour = (int) $topHour['hour'];
        $label    = $this->formatHourWindow($bestHour);

        // Compute quiz multiplier: avg score on peak-window days vs overall
        $overallAvg = (float) ($this->conn->fetchOne(
            "SELECT AVG(sub.score_pct)
             FROM (
                 SELECT qa.id,
                        (SUM(IF(qan.is_correct, 1, 0)) / NULLIF(COUNT(*), 0)) * 100 AS score_pct
                 FROM quiz_attempts qa
                 JOIN quiz_answers qan ON qan.attempt_id = qa.id
                 WHERE qa.student_user_id = ?
                 GROUP BY qa.id
             ) sub",
            [$userId]
        ) ?? 0);

        $multiplier = null;
        if ($overallAvg > 0) {
            $peakAvg = (float) ($this->conn->fetchOne(
                "SELECT AVG(sub.score_pct)
                 FROM (
                     SELECT qa.id,
                            (SUM(IF(qan.is_correct, 1, 0)) / NULLIF(COUNT(*), 0)) * 100 AS score_pct
                     FROM quiz_attempts qa
                     JOIN quiz_answers qan ON qan.attempt_id = qa.id
                     WHERE qa.student_user_id = ?
                       AND EXISTS (
                           SELECT 1
                           FROM pomodoro_sessions ps2
                           JOIN tasks t2 ON ps2.task_id = t2.id
                           WHERE (t2.student_user_id = ? OR t2.created_by_teacher_id = ?)
                             AND DATE(ps2.started_at) = DATE(qa.created_at)
                             AND HOUR(ps2.started_at) BETWEEN ? AND ?
                       )
                     GROUP BY qa.id
                 ) sub",
                [$userId, $userId, $userId, $bestHour, min(23, $bestHour + 2)]
            ) ?? 0);

            if ($peakAvg > 0) {
                $multiplier = round($peakAvg / $overallAvg, 1);
            }
        }

        return [
            'label'     => $label,
            'multiplier'=> $multiplier,
            'best_hour' => $bestHour,
        ];
    }

    private function formatHourWindow(int $hour): string
    {
        $fmt = static function (int $h): string {
            if ($h === 0) return '12am';
            if ($h < 12) return "{$h}am";
            if ($h === 12) return '12pm';
            return ($h - 12) . 'pm';
        };
        return $fmt($hour) . '–' . $fmt(min(23, $hour + 2));
    }

    /**
     * Courses where 20–75% progress but not accessed for 7+ days.
     */
    private function getAbandonedCourses(int $userId): array
    {
        return $this->conn->fetchAllAssociative(
            "SELECT c.title, c.level, cp.progress_percent, cp.last_accessed, m.name AS module_name
             FROM course_progress cp
             JOIN courses c ON cp.course_id = c.id
             LEFT JOIN modules m ON c.module_id = m.id
             WHERE cp.user_id = ?
               AND cp.progress_percent BETWEEN 20 AND 75
               AND cp.completed_at IS NULL
               AND cp.last_accessed < DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY cp.last_accessed ASC
             LIMIT 5",
            [$userId]
        );
    }

    /**
     * Compare user_activity count this week vs last week.
     */
    private function computeLearningVelocity(int $userId): array
    {
        $thisWeek = (int) ($this->conn->fetchOne(
            "SELECT COUNT(*) FROM user_activity
             WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            [$userId]
        ) ?? 0);

        $lastWeek = (int) ($this->conn->fetchOne(
            "SELECT COUNT(*) FROM user_activity
             WHERE user_id = ?
               AND created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                  AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            [$userId]
        ) ?? 0);

        $changePct = $lastWeek > 0
            ? round((($thisWeek - $lastWeek) / $lastWeek) * 100)
            : ($thisWeek > 0 ? 100 : 0);

        return [
            'this_week'  => $thisWeek,
            'last_week'  => $lastWeek,
            'change_pct' => $changePct,
        ];
    }

    /**
     * Detect knowledge gaps:
     * 1) Modules the user has never touched
     * 2) Quizzes where their average score < 60%
     */
    private function detectKnowledgeGaps(int $userId): array
    {
        $gaps = [];

        // Untouched modules
        $untouched = $this->conn->fetchAllAssociative(
            "SELECT m.name
             FROM modules m
             WHERE NOT EXISTS (
                 SELECT 1 FROM course_progress cp
                 JOIN courses c ON cp.course_id = c.id
                 WHERE c.module_id = m.id AND cp.user_id = ?
             )
             LIMIT 3",
            [$userId]
        );

        foreach ($untouched as $m) {
            $gaps[] = ['subject' => $m['name'], 'detail' => '0% coverage — never started'];
        }

        // Low-score quiz topics
        $lowScores = $this->conn->fetchAllAssociative(
            "SELECT dq.title,
                    COUNT(qa.id) AS attempts,
                    ROUND(AVG(sub.score_pct), 1) AS avg_score
             FROM quiz_attempts qa
             JOIN diagnostic_quizzes dq ON dq.id = qa.quiz_id
             JOIN (
                 SELECT attempt_id,
                        (SUM(IF(is_correct, 1, 0)) / NULLIF(COUNT(*), 0)) * 100 AS score_pct
                 FROM quiz_answers
                 GROUP BY attempt_id
             ) sub ON sub.attempt_id = qa.id
             WHERE qa.student_user_id = ?
             GROUP BY dq.id, dq.title
             HAVING avg_score < 60
             ORDER BY avg_score ASC
             LIMIT 3",
            [$userId]
        );

        foreach ($lowScores as $q) {
            $gaps[] = [
                'subject' => $q['title'],
                'detail'  => "{$q['attempts']} attempt(s), avg score {$q['avg_score']}% — needs review",
            ];
        }

        return $gaps;
    }

    /**
     * Most recent active incomplete course = the micro-goal target.
     */
    private function computeMicroGoal(int $userId): ?array
    {
        $course = $this->conn->fetchAssociative(
            "SELECT c.title, cp.progress_percent
             FROM course_progress cp
             JOIN courses c ON cp.course_id = c.id
             WHERE cp.user_id = ?
               AND cp.progress_percent BETWEEN 1 AND 90
               AND cp.completed_at IS NULL
             ORDER BY cp.last_accessed DESC
             LIMIT 1",
            [$userId]
        );

        if (!$course) return null;

        $remaining      = 100 - (int) $course['progress_percent'];
        $sessionsNeeded = max(1, (int) ceil($remaining / 33)); // ~33% per session

        return [
            'title'            => $course['title'],
            'progress_percent' => (int) $course['progress_percent'],
            'sessions_needed'  => $sessionsNeeded,
        ];
    }

    private function getFocusMinutesThisWeek(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COALESCE(SUM(ps.work_minutes * ps.cycles), 0)
             FROM pomodoro_sessions ps
             JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?)
               AND ps.started_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            [$userId, $userId]
        ) ?? 0);
    }

    private function getCoursesCompletedThisWeek(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COUNT(*) FROM course_progress
             WHERE user_id = ? AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            [$userId]
        ) ?? 0);
    }

    private function getQuizAvgThisWeek(int $userId): ?float
    {
        $avg = $this->conn->fetchOne(
            "SELECT AVG(sub.score_pct)
             FROM (
                 SELECT qa.id,
                        (SUM(IF(qan.is_correct, 1, 0)) / NULLIF(COUNT(*), 0)) * 100 AS score_pct
                 FROM quiz_attempts qa
                 JOIN quiz_answers qan ON qan.attempt_id = qa.id
                 WHERE qa.student_user_id = ?
                   AND qa.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY qa.id
             ) sub",
            [$userId]
        );

        return ($avg !== null && $avg !== false) ? (float) $avg : null;
    }

    private function getActiveDaysThisWeek(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COUNT(DISTINCT DATE(created_at)) FROM user_activity
             WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            [$userId]
        ) ?? 0);
    }

    private function getTotalXp(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COALESCE(SUM(xp_earned), 0) FROM course_progress WHERE user_id = ?",
            [$userId]
        ) ?? 0);
    }

    private function getTotalCoursesEnrolled(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COUNT(*) FROM course_progress WHERE user_id = ?",
            [$userId]
        ) ?? 0);
    }

    private function getTotalCoursesCompleted(int $userId): int
    {
        return (int) ($this->conn->fetchOne(
            "SELECT COUNT(*) FROM course_progress WHERE user_id = ? AND completed_at IS NOT NULL",
            [$userId]
        ) ?? 0);
    }

    /**
     * Composite health score (0–100) from all metrics.
     *
     * Weights:
     *   Active days     → up to +28 pts  (4 pts × day)
     *   Focus minutes   → up to +15 pts  (1 pt per 8 min)
     *   Velocity growth → up to +12 pts
     *   Quiz average    → up to +10 pts
     *   Abandoned       → up to −15 pts  (−5 per course)
     *   Knowledge gaps  → up to −10 pts  (−2 per gap)
     *   Base            → 35
     */
    private function computeHealthScore(array $metrics): int
    {
        $score = 35;

        $score += min(28, ($metrics['active_days_week'] ?? 0) * 4);
        $score += min(15, (int) (($metrics['focus_minutes_week'] ?? 0) / 8));

        $velChange = $metrics['learning_velocity']['change_pct'] ?? 0;
        if ($velChange >= 20) $score += 12;
        elseif ($velChange >= 5) $score += 8;
        elseif ($velChange >= 0) $score += 4;
        elseif ($velChange < -30) $score -= 8;
        elseif ($velChange < -10) $score -= 4;

        $quizAvg = $metrics['quiz_avg_week'] ?? null;
        if ($quizAvg !== null) {
            $score += min(10, (int) ($quizAvg / 10));
        }

        $score -= min(15, count($metrics['abandoned_courses'] ?? []) * 5);
        $score -= min(10, count($metrics['knowledge_gaps'] ?? []) * 2);

        return max(0, min(100, $score));
    }
}
