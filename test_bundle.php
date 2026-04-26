<?php
/**
 * SmartCourseBundle Manual Test Script
 * Run: php test_bundle.php
 *
 * Tests:
 *   1. Bundle config parameters loaded
 *   2. AnalyticsService - getGlobalStats()
 *   3. AnalyticsService - getCourseStats($id)
 *   4. AnalyticsService - getTopCourses()
 *   5. AnalyticsService - getUserEngagement($userId)
 *   6. RecommendationService - getRecommendations($userId)
 *   7. RecommendationService - getTrending()
 *   8. RecommendationService - search($q)
 *   9. CourseActivitySubscriber - onCourseViewed (writes to user_activity)
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\SmartCourseBundle\Service\AnalyticsService;
use App\Service\RecommendationService;
use App\SmartCourseBundle\Event\CourseViewedEvent;

// Stub env vars needed for container boot in CLI context
$_SERVER['APP_ENV']    ??= 'dev';
$_SERVER['APP_DEBUG']  ??= '1';
$_ENV['MAILER_DSN']    ??= 'null://null';
$_SERVER['MAILER_DSN'] ??= 'null://null';

(function () {
    $kernel = new \App\Kernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    $pass  = 0;
    $fail  = 0;
    $lines = [];

    $check = function (string $label, $result) use (&$pass, &$fail, &$lines) {
        $ok = !empty($result) && $result !== ['error' => 'Analytics disabled'];
        $icon = $ok ? '✔' : '✘';
        $lines[] = sprintf("  %s  %s", $icon, $label);
        if ($ok) { $pass++; } else { $fail++; }
        if (is_array($result)) {
            foreach ($result as $k => $v) {
                if (!is_array($v)) {
                    $lines[] = sprintf("       %-30s %s", $k.':', $v);
                }
            }
        }
        $lines[] = '';
    };

    echo "\n╔══════════════════════════════════════════════╗\n";
    echo   "║       SmartCourseBundle — Live Tests         ║\n";
    echo   "╚══════════════════════════════════════════════╝\n\n";

    // ── 1. Config params ────────────────────────────────────────────────────
    $lines[] = "── 1. Bundle Configuration Parameters ──";
    $params = [
        'smart_course.recommendation.enabled',
        'smart_course.recommendation.strategy',
        'smart_course.recommendation.weights',
        'smart_course.notifications.email',
        'smart_course.analytics.enabled',
    ];
    foreach ($params as $p) {
        $val = $container->getParameter($p);
        $lines[] = sprintf("  ✔  %-42s %s", $p, is_bool($val) ? ($val?'true':'false') : (is_array($val) ? json_encode($val) : $val));
        $pass++;
    }
    $lines[] = '';

    // ── 2. AnalyticsService ─────────────────────────────────────────────────
    /** @var AnalyticsService $analytics */
    $analytics = $container->get(AnalyticsService::class);

    $lines[] = "── 2. AnalyticsService::getGlobalStats() ──";
    $check('Global stats', $analytics->getGlobalStats());

    $lines[] = "── 3. AnalyticsService::getCourseStats(1) ──";
    $check('Course 1 stats', $analytics->getCourseStats(1));

    $lines[] = "── 4. AnalyticsService::getTopCourses(3) ──";
    $top = $analytics->getTopCourses(3);
    $lines[] = sprintf("  %s  Top courses (%d returned)", count($top) > 0 ? '✔' : '✘', count($top));
    foreach ($top as $i => $c) {
        $lines[] = sprintf("       #%d %-30s score=%s", $i+1, $c['title'], $c['analytics_score']);
    }
    if (count($top) > 0) { $pass++; } else { $fail++; }
    $lines[] = '';

    $lines[] = "── 5. AnalyticsService::getUserEngagement(2) ──";
    $check('User 2 engagement', $analytics->getUserEngagement(2));

    // ── 3. RecommendationService ────────────────────────────────────────────
    /** @var RecommendationService $rec */
    $rec = new RecommendationService($container->get('doctrine.dbal.default_connection'));

    $lines[] = "── 6. RecommendationService::getRecommendations(2) ──";
    $recs = $rec->getRecommendations(2, 5);
    $lines[] = sprintf("  %s  Recommendations (%d returned)", count($recs) > 0 ? '✔' : '✘', count($recs));
    foreach ($recs as $i => $r) {
        $lines[] = sprintf("       #%d %-28s score=%.4f  sim=%.2f  pop=%.2f  hist=%.2f",
            $i+1, $r['title'], $r['score'], $r['score_similarity'], $r['score_popularity'], $r['score_history']);
    }
    if (count($recs) > 0) { $pass++; } else { $fail++; }
    $lines[] = '';

    $lines[] = "── 7. RecommendationService::getTrending() ──";
    $trend = $rec->getTrending(3);
    $lines[] = sprintf("  %s  Trending courses (%d returned)", count($trend) > 0 ? '✔' : '✘', count($trend));
    foreach ($trend as $i => $c) {
        $lines[] = sprintf("       #%d %-28s trend_score=%s", $i+1, $c['title'], $c['trend_score']);
    }
    if (count($trend) > 0) { $pass++; } else { $fail++; }
    $lines[] = '';

    $lines[] = "── 8. RecommendationService::search('math') ──";
    $sr = $rec->search('math', 5);
    $lines[] = sprintf("  %s  Search results (%d found)", count($sr['results']) > 0 ? '✔' : '✘', count($sr['results']));
    foreach ($sr['results'] as $r) {
        $lines[] = sprintf("       • %-28s relevance=%s", $r['title'], $r['relevance']);
    }
    if (count($sr['results']) > 0) { $pass++; } else { $fail++; }
    $lines[] = '';

    // ── 4. EventSubscriber ──────────────────────────────────────────────────
    $lines[] = "── 9. CourseActivitySubscriber (EventDispatcher) ──";
    try {
        $dispatcher = $container->get('event_dispatcher');
        $before = $container->get('doctrine.dbal.default_connection')
            ->fetchOne('SELECT COUNT(*) FROM user_activity WHERE user_id = 2 AND activity_type = ?', ['view']);
        $dispatcher->dispatch(new CourseViewedEvent(2, 1), CourseViewedEvent::NAME);
        $after = $container->get('doctrine.dbal.default_connection')
            ->fetchOne('SELECT COUNT(*) FROM user_activity WHERE user_id = 2 AND activity_type = ?', ['view']);
        $recorded = (int)$after > (int)$before;
        $lines[] = sprintf("  %s  Event dispatched — user_activity rows: %d → %d",
            $recorded ? '✔' : '✘', $before, $after);
        if ($recorded) { $pass++; } else { $fail++; }
    } catch (\Throwable $e) {
        $lines[] = "  ✘  EventDispatcher error: " . $e->getMessage();
        $fail++;
    }
    $lines[] = '';

    // ── Summary ─────────────────────────────────────────────────────────────
    foreach ($lines as $l) { echo $l . "\n"; }
    echo "╔══════════════════════════════════════════════╗\n";
    echo sprintf("║  RESULT: %d passed, %d failed%s║\n", $pass, $fail, str_repeat(' ', 18 - strlen("$pass")- strlen("$fail")));
    echo "╚══════════════════════════════════════════════╝\n\n";

    $kernel->shutdown();
})();
