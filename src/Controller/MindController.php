<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\LearnerContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LearnAdapt Mind Controller
 *
 * Routes:
 *   GET  /mind                — Dashboard (last report + generate button)
 *   POST /mind/generate       — Generate report for current user (AJAX)
 *   GET  /mind/report/{id}    — Full report page
 *   GET  /mind/report/{id}/print — Print-ready version
 */
#[Route('/mind', name: 'app_mind_')]
class MindController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LearnerContextBuilder  $contextBuilder,
        private readonly HttpClientInterface    $httpClient,
    ) {}

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) return null;
        return $this->userRepository->find((int) $auth['id']);
    }

    // ── GET /mind ────────────────────────────────────────────────────────────

    #[Route('', name: 'dashboard')]
    public function dashboard(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return $this->redirectToRoute('app_login');

        $uid = $user->getId();

        $lastReport = $this->conn()->fetchAssociative(
            "SELECT * FROM mind_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
            [$uid]
        );

        $allReports = $this->conn()->fetchAllAssociative(
            "SELECT id, week_start, week_end, health_score, groq_used, created_at
             FROM mind_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 12",
            [$uid]
        );

        if ($lastReport && isset($lastReport['metrics'])) {
            $lastReport['metrics'] = json_decode((string) $lastReport['metrics'], true);
        }

        // Check if this week's report already exists
        $weekStart        = date('Y-m-d', strtotime('monday this week'));
        $thisWeekExists   = (bool) $this->conn()->fetchOne(
            "SELECT id FROM mind_reports WHERE user_id = ? AND week_start = ?",
            [$uid, $weekStart]
        );

        return $this->render('mind/dashboard.html.twig', [
            'darkPage'       => true,
            'user'           => $user,
            'lastReport'     => $lastReport,
            'allReports'     => $allReports,
            'thisWeekExists' => $thisWeekExists,
        ]);
    }

    // ── POST /mind/generate ───────────────────────────────────────────────────

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $userId    = $user->getId();
        $weekStart = date('Y-m-d', strtotime('monday this week'));

        // Remove existing report for this week if regenerating
        $this->conn()->executeStatement(
            "DELETE FROM mind_reports WHERE user_id = ? AND week_start = ?",
            [$userId, $weekStart]
        );

        // Build all metrics (pure PHP, no API)
        $metrics     = $this->contextBuilder->buildMetrics($userId);
        $prompt      = $this->contextBuilder->buildGroqPrompt($metrics);
        $healthScore = (int) ($metrics['health_score'] ?? 50);

        // Call Groq
        $narrative = null;
        $groqUsed  = false;
        $apiKey    = $this->getParameter('app.groq_api_key');

        if ($apiKey && $apiKey !== 'your-groq-api-key-here') {
            try {
                $response  = $this->httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'       => 'llama-3.3-70b-versatile',
                        'messages'    => [['role' => 'user', 'content' => $prompt]],
                        'max_tokens'  => 600,
                        'temperature' => 0.6,
                    ],
                    'timeout' => 30,
                ]);
                $result    = $response->toArray();
                $narrative = $result['choices'][0]['message']['content'] ?? null;
                $groqUsed  = (bool) $narrative;
            } catch (\Throwable) {
                // Fall through to offline
            }
        }

        // Offline fallback
        if (!$narrative) {
            $narrative = $this->buildOfflineNarrative($metrics);
        }

        // Persist report
        $this->conn()->executeStatement(
            "INSERT INTO mind_reports
                 (user_id, week_start, week_end, metrics, ai_narrative, health_score, groq_used, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $metrics['week_start'],
                $metrics['week_end'],
                json_encode($metrics),
                $narrative,
                $healthScore,
                $groqUsed ? 1 : 0,
            ]
        );

        $reportId = (int) $this->conn()->lastInsertId();

        return $this->json([
            'ok'           => true,
            'report_id'    => $reportId,
            'health_score' => $healthScore,
            'redirect'     => $this->generateUrl('app_mind_report', ['id' => $reportId]),
        ]);
    }

    // ── GET /mind/report/{id} ─────────────────────────────────────────────────

    #[Route('/report/{id}', name: 'report')]
    public function report(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return $this->redirectToRoute('app_login');

        $report = $this->conn()->fetchAssociative(
            "SELECT * FROM mind_reports WHERE id = ? AND user_id = ?",
            [$id, $user->getId()]
        );

        if (!$report) throw $this->createNotFoundException('Report not found.');

        $report['metrics'] = json_decode((string) $report['metrics'], true);

        return $this->render('mind/report.html.twig', [
            'darkPage' => true,
            'user'     => $user,
            'report'   => $report,
        ]);
    }

    // ── GET /mind/report/{id}/print ───────────────────────────────────────────

    #[Route('/report/{id}/print', name: 'report_print')]
    public function reportPrint(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return $this->redirectToRoute('app_login');

        $report = $this->conn()->fetchAssociative(
            "SELECT * FROM mind_reports WHERE id = ? AND user_id = ?",
            [$id, $user->getId()]
        );

        if (!$report) throw $this->createNotFoundException('Report not found.');

        $report['metrics'] = json_decode((string) $report['metrics'], true);

        return $this->render('mind/report_print.html.twig', [
            'user'   => $user,
            'report' => $report,
        ]);
    }

    // ── Offline narrative fallback ─────────────────────────────────────────────

    private function buildOfflineNarrative(array $metrics): string
    {
        $name       = $metrics['user_name'] ?? 'Learner';
        $vel        = $metrics['learning_velocity'] ?? ['change_pct' => 0];
        $velStr     = ($vel['change_pct'] >= 0 ? '+' : '') . $vel['change_pct'] . '%';
        $peak       = $metrics['peak_window']['label'] ?? null;
        $focus      = $metrics['focus_minutes_week'] ?? 0;
        $activeDays = $metrics['active_days_week'] ?? 0;
        $abandoned  = $metrics['abandoned_courses'] ?? [];
        $gaps       = $metrics['knowledge_gaps'] ?? [];
        $goal       = $metrics['micro_goal'] ?? null;
        $xp         = $metrics['total_xp'] ?? 0;
        $quizAvg    = $metrics['quiz_avg_week'] ?? null;

        $weekStart = date('M d', strtotime($metrics['week_start']));
        $weekEnd   = date('M d, Y', strtotime($metrics['week_end']));

        $lines   = [];
        $lines[] = "{$name}, here's your LearnAdapt Mind weekly cognitive report for {$weekStart}–{$weekEnd}:\n";
        $lines[] = "— Focus this week: {$focus} minutes across {$activeDays} active day(s)";

        if ($peak && $peak !== 'No focus data yet') {
            $mult    = $metrics['peak_window']['multiplier'] ?? null;
            $multStr = $mult ? " ({$mult}× better performance during this window)" : '';
            $lines[] = "— Peak performance window: {$peak}{$multStr}";
        }

        if ($quizAvg !== null) {
            $lines[] = "— Quiz average this week: " . round($quizAvg, 1) . '%';
        }

        $lines[] = "— Learning velocity: {$velStr} vs last week";
        $lines[] = "— Total XP accumulated: {$xp}";

        if (!empty($abandoned)) {
            $count   = count($abandoned);
            $titles  = implode(', ', array_map(fn($c) => '"' . $c['title'] . '"', array_slice($abandoned, 0, 2)));
            $lines[] = "— {$count} stalled course(s): {$titles}";
            if ($count >= 2) {
                $avgPct  = round(array_sum(array_column($abandoned, 'progress_percent')) / $count);
                $lines[] = "— Pattern: consistent abandonment around {$avgPct}% — consider reviewing prerequisite material.";
            }
        }

        if (!empty($gaps)) {
            $lines[] = "— Knowledge gaps identified:";
            foreach (array_slice($gaps, 0, 3) as $g) {
                $lines[] = "  • {$g['subject']}: {$g['detail']}";
            }
        }

        $lines[] = '';

        if ($goal) {
            $lines[] = "🎯 Micro-goal for next week: Complete \"{$goal['title']}\" (currently {$goal['progress_percent']}% done) in {$goal['sessions_needed']} focused session(s).";
        } else {
            $lines[] = "🎯 Micro-goal: Enroll in a new course this week and hit 30% progress.";
        }

        $lines[] = '';
        $lines[] = "— Generated by LearnAdapt Mind v1.0";

        return implode("\n", $lines);
    }
}
