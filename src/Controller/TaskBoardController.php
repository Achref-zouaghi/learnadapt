<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class TaskBoardController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        return $this->userRepository->find((int) $auth['id']);
    }

    private function isOwner(array $task, int $userId): bool
    {
        return (int) ($task['student_user_id'] ?? 0) === $userId
            || (int) ($task['created_by_teacher_id'] ?? 0) === $userId;
    }

    #[Route('/taskboard', name: 'app_taskboard')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $uid = $user->getId();
        $tasks = $this->conn()->fetchAllAssociative(
            'SELECT t.*, c.title as course_title
             FROM tasks t LEFT JOIN courses c ON t.linked_course_id = c.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?
             ORDER BY t.created_at DESC',
            [$uid, $uid]
        );

        $columns = ['TODO' => [], 'IN_PROGRESS' => [], 'DONE' => [], 'BLOCKED' => []];
        $totalTasks = 0;
        $today = (new \DateTime('today'))->format('Y-m-d');
        foreach ($tasks as $task) {
            $status = strtoupper($task['status'] ?? 'TODO');
            if (!isset($columns[$status])) { $columns[$status] = []; }
            $task['is_overdue'] = false;
            $task['is_due_today'] = false;
            if ($task['due_date'] && $status !== 'DONE') {
                $task['is_overdue'] = $task['due_date'] < $today;
                $task['is_due_today'] = $task['due_date'] === $today;
            }
            $columns[$status][] = $task;
            $totalTasks++;
        }

        // Auto-generate notifications for overdue & due-today tasks
        $this->generateTaskNotifications($tasks, $uid, $today);

        $pomodoroStats = $this->conn()->fetchAssociative(
            'SELECT COUNT(*) as total_sessions, COALESCE(SUM(work_minutes * cycles), 0) as total_minutes,
                    COUNT(CASE WHEN completed = 1 THEN 1 END) as completed_sessions
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?',
            [$uid, $uid]
        );

        // Last 7 days pomodoro activity (for chart)
        $pomoDays = $this->conn()->fetchAllAssociative(
            "SELECT DATE(ps.started_at) as day, SUM(ps.work_minutes * ps.cycles) as minutes, COUNT(*) as sessions
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?)
               AND ps.started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(ps.started_at) ORDER BY day ASC",
            [$uid, $uid]
        );

        // Fill missing days
        $weekData = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = (new \DateTime("-{$i} days"))->format('Y-m-d');
            $weekData[$d] = ['day' => $d, 'minutes' => 0, 'sessions' => 0];
        }
        foreach ($pomoDays as $row) {
            if (isset($weekData[$row['day']])) {
                $weekData[$row['day']]['minutes'] = (int) $row['minutes'];
                $weekData[$row['day']]['sessions'] = (int) $row['sessions'];
            }
        }
        $weekData = array_values($weekData);

        // Top 5 tasks by focus time
        $topTasks = $this->conn()->fetchAllAssociative(
            "SELECT t.title, SUM(ps.work_minutes * ps.cycles) as total_min, COUNT(ps.id) as sess_count
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?) AND ps.task_id IS NOT NULL
             GROUP BY t.id, t.title ORDER BY total_min DESC LIMIT 5",
            [$uid, $uid]
        );

        // Task completion rate
        $completionRate = $totalTasks > 0 ? round((count($columns['DONE']) / $totalTasks) * 100) : 0;

        // Tasks created this week
        $tasksThisWeek = $this->conn()->fetchOne(
            "SELECT COUNT(*) FROM tasks WHERE (student_user_id = ? OR created_by_teacher_id = ?)
             AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
            [$uid, $uid]
        );

        // Average daily focus (last 7 days)
        $totalWeekMinutes = array_sum(array_column($weekData, 'minutes'));
        $avgDailyFocus = round($totalWeekMinutes / 7);

        // Streak: consecutive days with pomodoro sessions
        $streakDays = $this->conn()->fetchAllAssociative(
            "SELECT DISTINCT DATE(ps.started_at) as day
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?)
             ORDER BY day DESC",
            [$uid, $uid]
        );
        $streak = 0;
        $checkDate = new \DateTime('today');
        foreach ($streakDays as $sd) {
            if ($sd['day'] === $checkDate->format('Y-m-d')) {
                $streak++;
                $checkDate->modify('-1 day');
            } else {
                break;
            }
        }

        // Build Pomodoro weekly chart with UX ChartJS (Symfony UX)
        $chartLabels = array_map(fn($d) => date('D', strtotime($d['day'])), $weekData);
        $chartMinutes = array_column($weekData, 'minutes');
        $pomodoroChart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $pomodoroChart->setData([
            'labels' => $chartLabels,
            'datasets' => [[
                'label' => 'Focus Minutes',
                'data' => $chartMinutes,
                'backgroundColor' => 'rgba(123, 97, 241, 0.65)',
                'borderColor' => 'rgba(123, 97, 241, 1)',
                'borderWidth' => 2,
                'borderRadius' => 8,
            ]],
        ]);
        $pomodoroChart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['color' => 'rgba(255,255,255,0.6)']],
                'x' => ['ticks' => ['color' => 'rgba(255,255,255,0.6)']],
            ],
        ]);

        return $this->render('taskboard/index.html.twig', [
            'darkPage' => true,
            'columns' => $columns,
            'totalTasks' => $totalTasks,
            'user' => $user,
            'pomodoroStats' => $pomodoroStats,
            'weekData' => $weekData,
            'topTasks' => $topTasks,
            'completionRate' => $completionRate,
            'tasksThisWeek' => (int) $tasksThisWeek,
            'avgDailyFocus' => $avgDailyFocus,
            'streak' => $streak,
            'pomodoroChart' => $pomodoroChart,
        ]);
    }

    #[Route('/taskboard/add', name: 'app_taskboard_add', methods: ['POST'])]
    public function addTask(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $title = trim($request->request->get('title', ''));
        $description = trim($request->request->get('description', ''));
        $status = $request->request->get('status', 'TODO');
        $priority = $request->request->get('priority', 'MEDIUM');
        $dueDateStr = trim($request->request->get('due_date', ''));

        if ($title === '') {
            $this->addFlash('error', 'flash.task_title_required');
            return $this->redirectToRoute('app_taskboard');
        }

        if ($dueDateStr !== '' && $dueDateStr < date('Y-m-d')) {
            $this->addFlash('error', 'flash.task_date_past');
            return $this->redirectToRoute('app_taskboard');
        }

        $allowedStatuses = ['TODO', 'IN_PROGRESS', 'DONE', 'BLOCKED'];
        $allowedPriorities = ['LOW', 'MEDIUM', 'HIGH'];
        if (!in_array($status, $allowedStatuses, true)) { $status = 'TODO'; }
        if (!in_array($priority, $allowedPriorities, true)) { $priority = 'MEDIUM'; }

        $this->conn()->executeStatement(
            'INSERT INTO tasks (student_user_id, title, description, status, priority, due_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$user->getId(), $title, $description ?: null, $status, $priority, $dueDateStr ?: null]
        );

        $this->addFlash('success', 'flash.task_added');
        return $this->redirectToRoute('app_taskboard');
    }

    #[Route('/taskboard/edit/{id}', name: 'app_taskboard_edit', methods: ['POST'])]
    public function editTask(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $task = $this->conn()->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            $this->addFlash('error', 'flash.task_not_found');
            return $this->redirectToRoute('app_taskboard');
        }

        $title = trim($request->request->get('title', ''));
        $description = trim($request->request->get('description', ''));
        $status = $request->request->get('status', $task['status']);
        $priority = $request->request->get('priority', $task['priority']);
        $dueDateStr = trim($request->request->get('due_date', ''));

        if ($title === '') {
            $this->addFlash('error', 'flash.task_title_required');
            return $this->redirectToRoute('app_taskboard');
        }

        if ($dueDateStr !== '' && $dueDateStr < date('Y-m-d')) {
            $this->addFlash('error', 'flash.task_date_past');
            return $this->redirectToRoute('app_taskboard');
        }

        $allowedStatuses = ['TODO', 'IN_PROGRESS', 'DONE', 'BLOCKED'];
        $allowedPriorities = ['LOW', 'MEDIUM', 'HIGH'];
        if (!in_array($status, $allowedStatuses, true)) { $status = $task['status']; }
        if (!in_array($priority, $allowedPriorities, true)) { $priority = $task['priority']; }

        $this->conn()->executeStatement(
            'UPDATE tasks SET title = ?, description = ?, status = ?, priority = ?, due_date = ?, updated_at = NOW() WHERE id = ?',
            [$title, $description ?: null, $status, $priority, $dueDateStr ?: null, $id]
        );

        $this->addFlash('success', 'flash.task_updated');
        return $this->redirectToRoute('app_taskboard');
    }

    #[Route('/taskboard/update-status/{id}', name: 'app_taskboard_update_status', methods: ['POST'])]
    public function updateStatus(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $task = $this->conn()->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            $this->addFlash('error', 'flash.task_not_found');
            return $this->redirectToRoute('app_taskboard');
        }

        $newStatus = $request->request->get('status', 'TODO');
        $allowed = ['TODO', 'IN_PROGRESS', 'DONE', 'BLOCKED'];
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';

        // Block overdue tasks from being marked DONE
        if ($newStatus === 'DONE' && $task['due_date'] && $task['due_date'] < date('Y-m-d') && strtoupper($task['status']) !== 'DONE') {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'error' => 'overdue_locked'], 422);
            }
            $this->addFlash('error', 'flash.task_overdue_locked');
            return $this->redirectToRoute('app_taskboard');
        }

        if (in_array($newStatus, $allowed, true)) {
            $this->conn()->executeStatement(
                'UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?',
                [$newStatus, $id]
            );
        }

        if ($isAjax) {
            return new JsonResponse(['ok' => true, 'status' => $newStatus]);
        }

        $this->addFlash('success', 'flash.task_updated');
        return $this->redirectToRoute('app_taskboard');
    }

    #[Route('/taskboard/export-pdf', name: 'app_taskboard_export_pdf')]
    public function exportPdf(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $uid = $user->getId();
        $tasks = $this->conn()->fetchAllAssociative(
            'SELECT t.*, c.title as course_title
             FROM tasks t LEFT JOIN courses c ON t.linked_course_id = c.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?
             ORDER BY FIELD(t.status, "TODO","IN_PROGRESS","BLOCKED","DONE"), t.priority DESC, t.created_at DESC',
            [$uid, $uid]
        );

        $pomodoroStats = $this->conn()->fetchAssociative(
            'SELECT COUNT(*) as total_sessions, COALESCE(SUM(work_minutes * cycles), 0) as total_minutes
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?',
            [$uid, $uid]
        );

        return $this->render('taskboard/export_pdf.html.twig', [
            'tasks' => $tasks,
            'user' => $user,
            'pomodoroStats' => $pomodoroStats,
            'exportDate' => new \DateTime(),
        ]);
    }

    #[Route('/taskboard/delete/{id}', name: 'app_taskboard_delete', methods: ['POST'])]
    public function deleteTask(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $task = $this->conn()->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            $this->addFlash('error', 'flash.task_not_found');
            return $this->redirectToRoute('app_taskboard');
        }

        $this->conn()->executeStatement('DELETE FROM pomodoro_sessions WHERE task_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM tasks WHERE id = ?', [$id]);

        $this->addFlash('success', 'flash.task_deleted');
        return $this->redirectToRoute('app_taskboard');
    }

    #[Route('/taskboard/pomodoro/start/{taskId}', name: 'app_taskboard_pomodoro_start', methods: ['POST'])]
    public function startPomodoro(int $taskId, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        if ($taskId <= 0) {
            return new JsonResponse(['error' => 'task required'], 400);
        }

        $task = $this->conn()->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$taskId]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            return new JsonResponse(['error' => 'not found'], 404);
        }

        $workMinutes = max(1, min(60, (int) $request->request->get('work_minutes', 25)));
        $breakMinutes = max(1, min(30, (int) $request->request->get('break_minutes', 5)));

        $this->conn()->executeStatement(
            'INSERT INTO pomodoro_sessions (task_id, work_minutes, break_minutes, cycles, started_at, completed)
             VALUES (?, ?, ?, 1, NOW(), 0)',
            [$taskId, $workMinutes, $breakMinutes]
        );
        $sessionId = (int) $this->conn()->lastInsertId();

        return new JsonResponse(['id' => $sessionId, 'work_minutes' => $workMinutes, 'break_minutes' => $breakMinutes]);
    }

    #[Route('/taskboard/pomodoro/complete/{id}', name: 'app_taskboard_pomodoro_complete', methods: ['POST'])]
    public function completePomodoro(int $id, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $this->conn()->executeStatement(
            'UPDATE pomodoro_sessions SET ended_at = NOW(), completed = 1 WHERE id = ?',
            [$id]
        );

        return new JsonResponse(['ok' => true]);
    }

    // ── AI Study Planner ──────────────────────────────────────────────────────

    #[Route('/taskboard/ai/plan', name: 'app_taskboard_ai_plan', methods: ['POST'])]
    public function aiGeneratePlan(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $goal    = trim($request->request->get('goal', ''));
        $subject = trim($request->request->get('subject', 'General'));
        $time    = max(15, min(480, (int) $request->request->get('time', 60)));
        $level   = $request->request->get('level', 'Intermediate');

        $allowedLevels = ['Beginner', 'Intermediate', 'Advanced'];
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'Intermediate';
        }

        if ($goal === '') {
            return new JsonResponse(['error' => 'Goal is required'], 400);
        }

        $apiKey = $this->getParameter('app.groq_api_key');
        if (!$apiKey || $apiKey === 'your-groq-api-key-here') {
            return new JsonResponse(['error' => 'AI not configured'], 503);
        }

        $systemPrompt = <<<PROMPT
You are a professional AI study planner. Your job is to decompose a study goal into structured, actionable subtasks optimized for a Pomodoro timer.

RULES:
- Return ONLY valid JSON, no markdown, no explanation
- The "plan" array must contain steps whose "duration" values sum to approximately {$time} minutes
- Each step must have: step (number), action (string, concise), duration (integer minutes), type (one of: read, summarize, quiz, exercise, review, practice), priority (HIGH/MEDIUM/LOW)
- Step durations should be Pomodoro-friendly (15, 20, 25, 30, 45, 50 minutes)
- Adapt to level: {$level}
- Maximum 8 steps

JSON format:
{{"plan":[{{"step":1,"action":"...","duration":25,"type":"read","priority":"HIGH"}}]}}
PROMPT;

        $userPrompt = "Study goal: {$goal}\nSubject: {$subject}\nAvailable time: {$time} minutes\nLevel: {$level}";

        try {
            $response = $this->httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => 'llama-3.3-70b-versatile',
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                    'max_tokens'  => 1200,
                    'temperature' => 0.4,
                ],
                'timeout' => 20,
            ]);

            $data    = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';

            // Extract JSON (strip markdown fences if present)
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $parsed  = json_decode(trim($content), true);

            if (!isset($parsed['plan']) || !is_array($parsed['plan'])) {
                return new JsonResponse(['error' => 'AI returned an unexpected format. Please try again.'], 500);
            }

            // Sanitize plan steps
            $plan = [];
            foreach ($parsed['plan'] as $step) {
                $plan[] = [
                    'step'     => (int)  ($step['step']     ?? 0),
                    'action'   => substr(trim((string)($step['action'] ?? '')), 0, 200),
                    'duration' => max(5, min(120, (int)($step['duration'] ?? 25))),
                    'type'     => in_array($step['type'] ?? '', ['read','summarize','quiz','exercise','review','practice'], true)
                                  ? $step['type'] : 'study',
                    'priority' => in_array($step['priority'] ?? '', ['HIGH','MEDIUM','LOW'], true)
                                  ? $step['priority'] : 'MEDIUM',
                ];
            }

            return new JsonResponse(['plan' => $plan, 'goal' => $goal, 'subject' => $subject]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'AI service unavailable: ' . $e->getMessage()], 503);
        }
    }

    #[Route('/taskboard/ai/import', name: 'app_taskboard_ai_import', methods: ['POST'])]
    public function aiImportPlan(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $body = json_decode($request->getContent(), true);
        $steps = $body['steps'] ?? [];

        if (!is_array($steps) || count($steps) === 0) {
            return new JsonResponse(['error' => 'No steps provided'], 400);
        }

        $created = [];
        foreach ($steps as $step) {
            $title    = substr(trim((string)($step['action'] ?? '')), 0, 255);
            $duration = max(5, min(120, (int)($step['duration'] ?? 25)));
            $type     = $step['type']     ?? 'study';
            $priority = in_array($step['priority'] ?? '', ['HIGH','MEDIUM','LOW'], true) ? $step['priority'] : 'MEDIUM';

            if ($title === '') {
                continue;
            }

            $desc = sprintf('[AI Plan] %s — %d min Pomodoro session', ucfirst($type), $duration);

            $this->conn()->executeStatement(
                'INSERT INTO tasks (student_user_id, title, description, status, priority, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [$user->getId(), $title, $desc, 'TODO', $priority]
            );
            $taskId = (int) $this->conn()->lastInsertId();

            // Pre-create a Pomodoro session for this task
            $this->conn()->executeStatement(
                'INSERT INTO pomodoro_sessions (task_id, work_minutes, break_minutes, cycles, started_at, completed)
                 VALUES (?, ?, ?, 1, NOW(), 0)',
                [$taskId, $duration, max(5, (int) round($duration / 5)), ]
            );

            $created[] = ['id' => $taskId, 'title' => $title, 'duration' => $duration];
        }

        return new JsonResponse(['ok' => true, 'created' => $created, 'count' => count($created)]);
    }

    #[Route('/taskboard/ai/report', name: 'app_taskboard_ai_report', methods: ['POST'])]
    public function aiWeeklyReport(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $apiKey = $this->getParameter('app.groq_api_key');
        if (!$apiKey || $apiKey === 'your-groq-api-key-here') {
            return new JsonResponse(['error' => 'AI not configured'], 503);
        }

        $uid = $user->getId();

        // Gather last 7 days data
        $weekData = $this->conn()->fetchAllAssociative(
            "SELECT DATE(ps.started_at) as day, DAYNAME(ps.started_at) as day_name,
                    SUM(ps.work_minutes * ps.cycles) as focus_min, COUNT(*) as sessions,
                    COUNT(CASE WHEN ps.completed=1 THEN 1 END) as completed
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?)
               AND ps.started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(ps.started_at), DAYNAME(ps.started_at)
             ORDER BY day ASC",
            [$uid, $uid]
        );

        $taskStats = $this->conn()->fetchAssociative(
            "SELECT COUNT(*) as total,
                    COUNT(CASE WHEN status='DONE' THEN 1 END) as done,
                    COUNT(CASE WHEN status='TODO' THEN 1 END) as todo,
                    COUNT(CASE WHEN status='IN_PROGRESS' THEN 1 END) as in_progress
             FROM tasks WHERE student_user_id = ? OR created_by_teacher_id = ?",
            [$uid, $uid]
        );

        $totalFocusMin = array_sum(array_column($weekData, 'focus_min'));
        $totalSessions = array_sum(array_column($weekData, 'sessions'));

        // Find best day
        $bestDay = '';
        $bestMin = 0;
        foreach ($weekData as $d) {
            if ($d['focus_min'] > $bestMin) {
                $bestMin = $d['focus_min'];
                $bestDay = $d['day_name'];
            }
        }

        $dataStr = "Weekly focus: {$totalFocusMin} minutes across {$totalSessions} sessions.\n";
        $dataStr .= "Best day: {$bestDay} ({$bestMin} min).\n";
        $dataStr .= 'Daily breakdown: ' . implode(', ', array_map(
            fn($d) => "{$d['day_name']}: {$d['focus_min']}min/{$d['sessions']}sess",
            $weekData
        )) . ".\n";
        $dataStr .= "Task board: {$taskStats['total']} total, {$taskStats['done']} done, {$taskStats['in_progress']} in progress, {$taskStats['todo']} todo.\n";
        $dataStr .= 'Completion rate: ' . ($taskStats['total'] > 0 ? round(($taskStats['done'] / $taskStats['total']) * 100) : 0) . "%.";

        $systemPrompt = <<<PROMPT
You are a premium AI productivity coach for students. Analyze study data and return a rich weekly report.

Return ONLY valid JSON:
{
  "score": 78,
  "grade": "B+",
  "summary": "Brief 1-sentence overall summary",
  "insights": [
    {"icon": "🕘", "title": "Best Study Time", "body": "..."},
    {"icon": "🔥", "title": "Streak Highlight", "body": "..."},
    {"icon": "⚠️", "title": "Watch Out", "body": "..."},
    {"icon": "💡", "title": "AI Tip", "body": "..."}
  ],
  "next_week": ["Actionable tip 1", "Actionable tip 2", "Actionable tip 3"]
}
Adapt tone: encouraging but honest. Be specific with numbers. Score is 0-100.
PROMPT;

        try {
            $response = $this->httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => 'llama-3.3-70b-versatile',
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => "Student study data this week:\n" . $dataStr],
                    ],
                    'max_tokens'  => 800,
                    'temperature' => 0.6,
                ],
                'timeout' => 20,
            ]);

            $data    = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $parsed  = json_decode(trim($content), true);

            if (!isset($parsed['score'])) {
                return new JsonResponse(['error' => 'AI returned an unexpected format'], 500);
            }

            $parsed['raw_data'] = [
                'total_focus_min' => $totalFocusMin,
                'total_sessions'  => $totalSessions,
                'best_day'        => $bestDay,
                'best_min'        => $bestMin,
            ];

            return new JsonResponse($parsed);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'AI service unavailable: ' . $e->getMessage()], 503);
        }
    }

    private function generateTaskNotifications(array $tasks, int $userId, string $today): void
    {
        foreach ($tasks as $task) {
            if (!$task['due_date'] || strtoupper($task['status']) === 'DONE') {
                continue;
            }

            $taskId = (int) $task['id'];
            $dueDate = $task['due_date'];
            $title = $task['title'];

            if ($dueDate < $today) {
                // Overdue — only ONE unread notification per task
                $exists = $this->conn()->fetchOne(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'TASK_OVERDUE' AND related_topic_id = ? AND is_read = 0",
                    [$userId, $taskId]
                );
                if (!$exists) {
                    $this->conn()->executeStatement(
                        "INSERT INTO notifications (user_id, type, title, message, is_read, related_topic_id, created_at) VALUES (?, 'TASK_OVERDUE', ?, ?, 0, ?, NOW())",
                        [$userId, '⚠️ Tâche en retard !', '"' . $title . '" a dépassé sa date limite.', $taskId]
                    );
                }
            } elseif ($dueDate === $today) {
                // Due today — only ONE unread notification per task
                $exists = $this->conn()->fetchOne(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'TASK_DUE_TODAY' AND related_topic_id = ? AND is_read = 0",
                    [$userId, $taskId]
                );
                if (!$exists) {
                    $this->conn()->executeStatement(
                        "INSERT INTO notifications (user_id, type, title, message, is_read, related_topic_id, created_at) VALUES (?, 'TASK_DUE_TODAY', ?, ?, 0, ?, NOW())",
                        [$userId, '⏰ Tâche à faire aujourd\'hui !', '"' . $title . '" est prévue pour aujourd\'hui.', $taskId]
                    );
                }
            }
        }
    }

    #[Route('/notification/read/{id}', name: 'app_notification_read', methods: ['GET'])]
    public function readNotification(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Mark as read (only if it belongs to this user)
        $this->conn()->executeStatement(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        return $this->redirectToRoute('app_taskboard');
    }

    #[Route('/notification/read-all', name: 'app_notification_read_all', methods: ['POST'])]
    public function readAllNotifications(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $this->conn()->executeStatement(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$user->getId()]
        );

        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?: $this->generateUrl('app_taskboard'));
    }
}
