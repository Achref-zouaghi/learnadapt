<?php

namespace App\Command;

use App\Service\LearnerContextBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generates LearnAdapt Mind weekly cognitive reports for all active users.
 *
 * Usage:
 *   php bin/console app:mind:generate-weekly-reports
 *   php bin/console app:mind:generate-weekly-reports --user-id=5
 *   php bin/console app:mind:generate-weekly-reports --force
 *
 * Schedule (cron — every Sunday at 11pm):
 *   0 23 * * 0 php /path/to/bin/console app:mind:generate-weekly-reports
 */
#[AsCommand(
    name: 'app:mind:generate-weekly-reports',
    description: 'Generate LearnAdapt Mind weekly cognitive reports for all active users',
)]
class GenerateWeeklyReportsCommand extends Command
{
    public function __construct(
        private readonly Connection            $conn,
        private readonly LearnerContextBuilder $contextBuilder,
        private readonly HttpClientInterface   $httpClient,
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', 'u', InputOption::VALUE_OPTIONAL, 'Generate for a specific user ID only')
            ->addOption('force',   'f', InputOption::VALUE_NONE,     'Regenerate even if report already exists for this week');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $apiKey    = (string) ($this->params->get('app.groq_api_key') ?? '');
        $force     = (bool) $input->getOption('force');
        $targetUid = $input->getOption('user-id');

        $io->title('LearnAdapt Mind — Weekly Cognitive Report Generator');
        $io->text("Week: <info>{$weekStart}</info> → " . date('Y-m-d', strtotime('sunday this week')));

        // ── Fetch users ─────────────────────────────────────────────────────
        if ($targetUid) {
            $users = $this->conn->fetchAllAssociative(
                "SELECT id, full_name, email FROM users WHERE id = ?",
                [(int) $targetUid]
            );
        } else {
            // Only users active in the last 30 days (avoids churned users)
            $users = $this->conn->fetchAllAssociative(
                "SELECT DISTINCT u.id, u.full_name, u.email
                 FROM users u
                 JOIN user_activity ua ON ua.user_id = u.id
                 WHERE ua.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY u.id"
            );
        }

        if (empty($users)) {
            $io->warning('No active users found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processing %d user(s)...', count($users)));

        $generated = 0;
        $skipped   = 0;
        $errors    = 0;

        foreach ($users as $userRow) {
            $uid  = (int) $userRow['id'];
            $name = $userRow['full_name'];

            // ── Skip if already generated (unless --force) ─────────────────
            $existing = $this->conn->fetchOne(
                "SELECT id FROM mind_reports WHERE user_id = ? AND week_start = ?",
                [$uid, $weekStart]
            );

            if ($existing && !$force) {
                $io->writeln("  <fg=yellow>SKIP</>  {$name} — report already exists for this week");
                $skipped++;
                continue;
            }

            if ($existing && $force) {
                $this->conn->executeStatement(
                    "DELETE FROM mind_reports WHERE user_id = ? AND week_start = ?",
                    [$uid, $weekStart]
                );
            }

            // ── Build metrics ──────────────────────────────────────────────
            try {
                $metrics     = $this->contextBuilder->buildMetrics($uid);
                $prompt      = $this->contextBuilder->buildGroqPrompt($metrics);
                $healthScore = (int) ($metrics['health_score'] ?? 50);

                // ── Call Groq API ──────────────────────────────────────────
                $narrative = null;
                $groqUsed  = false;

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
                    } catch (\Throwable $e) {
                        $io->writeln("  <fg=yellow>  Groq failed for {$name}: {$e->getMessage()} — using offline narrative</>");
                    }
                }

                // ── Offline fallback ───────────────────────────────────────
                if (!$narrative) {
                    $narrative = $this->buildOfflineNarrative($metrics);
                }

                // ── Save to DB ─────────────────────────────────────────────
                $this->conn->executeStatement(
                    "INSERT INTO mind_reports (user_id, week_start, week_end, metrics, ai_narrative, health_score, groq_used, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                    [
                        $uid,
                        $metrics['week_start'],
                        $metrics['week_end'],
                        json_encode($metrics),
                        $narrative,
                        $healthScore,
                        $groqUsed ? 1 : 0,
                    ]
                );

                $aiTag = $groqUsed ? '<fg=cyan>[Groq AI]</>' : '<fg=gray>[offline]</>';
                $io->writeln("  <fg=green>OK</>   {$name} — health score: <info>{$healthScore}/100</info> {$aiTag}");
                $generated++;

            } catch (\Throwable $e) {
                $io->writeln("  <fg=red>ERR</>  {$name}: {$e->getMessage()}");
                $errors++;
            }
        }

        $io->newLine();
        $io->success(sprintf(
            'Done — %d generated, %d skipped, %d errors.',
            $generated, $skipped, $errors
        ));

        if ($errors > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // ── Offline fallback narrative ──────────────────────────────────────────

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
            $multStr = $mult ? " ({$mult}× better performance)" : '';
            $lines[] = "— Peak performance window: {$peak}{$multStr}";
        }

        if ($quizAvg !== null) {
            $lines[] = "— Quiz average this week: " . round($quizAvg, 1) . '%';
        }

        $lines[] = "— Learning velocity: {$velStr} vs last week";
        $lines[] = "— All-time XP earned: {$xp}";

        if (!empty($abandoned)) {
            $count   = count($abandoned);
            $titles  = implode(', ', array_map(fn($c) => '"' . $c['title'] . '"', array_slice($abandoned, 0, 2)));
            $lines[] = "— {$count} stalled course(s) detected: {$titles}";

            if ($count >= 2) {
                $avgPct  = round(array_sum(array_column($abandoned, 'progress_percent')) / $count);
                $lines[] = "— Pattern: consistent abandonment around {$avgPct}% — consider reviewing prerequisite material at this stage.";
            }
        }

        if (!empty($gaps)) {
            $lines[] = "— Knowledge gaps:";
            foreach (array_slice($gaps, 0, 3) as $g) {
                $lines[] = "  • {$g['subject']}: {$g['detail']}";
            }
        }

        $lines[] = '';

        if ($goal) {
            $lines[] = "🎯 Micro-goal for next week: Complete \"{$goal['title']}\" (currently {$goal['progress_percent']}% done) in {$goal['sessions_needed']} focused Pomodoro session(s).";
        } else {
            $lines[] = "🎯 Micro-goal for next week: Enroll in a new course that challenges your current level.";
        }

        $lines[] = '';
        $lines[] = "— Generated by LearnAdapt Mind v1.0";

        return implode("\n", $lines);
    }
}
