<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TaskBoardController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
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
        $tasks = $this->connection->fetchAllAssociative(
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

        $pomodoroStats = $this->connection->fetchAssociative(
            'SELECT COUNT(*) as total_sessions, COALESCE(SUM(work_minutes * cycles), 0) as total_minutes,
                    COUNT(CASE WHEN completed = 1 THEN 1 END) as completed_sessions
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?',
            [$uid, $uid]
        );

        // Last 7 days pomodoro activity (for chart)
        $pomoDays = $this->connection->fetchAllAssociative(
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
        $topTasks = $this->connection->fetchAllAssociative(
            "SELECT t.title, SUM(ps.work_minutes * ps.cycles) as total_min, COUNT(ps.id) as sess_count
             FROM pomodoro_sessions ps JOIN tasks t ON ps.task_id = t.id
             WHERE (t.student_user_id = ? OR t.created_by_teacher_id = ?) AND ps.task_id IS NOT NULL
             GROUP BY t.id, t.title ORDER BY total_min DESC LIMIT 5",
            [$uid, $uid]
        );

        // Task completion rate
        $completionRate = $totalTasks > 0 ? round((count($columns['DONE']) / $totalTasks) * 100) : 0;

        // Tasks created this week
        $tasksThisWeek = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tasks WHERE (student_user_id = ? OR created_by_teacher_id = ?)
             AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
            [$uid, $uid]
        );

        // Average daily focus (last 7 days)
        $totalWeekMinutes = array_sum(array_column($weekData, 'minutes'));
        $avgDailyFocus = round($totalWeekMinutes / 7);

        // Streak: consecutive days with pomodoro sessions
        $streakDays = $this->connection->fetchAllAssociative(
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

        $this->connection->executeStatement(
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

        $task = $this->connection->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
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

        $this->connection->executeStatement(
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

        $task = $this->connection->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            $this->addFlash('error', 'flash.task_not_found');
            return $this->redirectToRoute('app_taskboard');
        }

        $newStatus = $request->request->get('status', 'TODO');
        $allowed = ['TODO', 'IN_PROGRESS', 'DONE', 'BLOCKED'];

        // Block overdue tasks from being marked DONE
        if ($newStatus === 'DONE' && $task['due_date'] && $task['due_date'] < date('Y-m-d') && strtoupper($task['status']) !== 'DONE') {
            $this->addFlash('error', 'flash.task_overdue_locked');
            return $this->redirectToRoute('app_taskboard');
        }

        if (in_array($newStatus, $allowed, true)) {
            $this->connection->executeStatement(
                'UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?',
                [$newStatus, $id]
            );
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
        $tasks = $this->connection->fetchAllAssociative(
            'SELECT t.*, c.title as course_title
             FROM tasks t LEFT JOIN courses c ON t.linked_course_id = c.id
             WHERE t.student_user_id = ? OR t.created_by_teacher_id = ?
             ORDER BY FIELD(t.status, "TODO","IN_PROGRESS","BLOCKED","DONE"), t.priority DESC, t.created_at DESC',
            [$uid, $uid]
        );

        $pomodoroStats = $this->connection->fetchAssociative(
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

        $task = $this->connection->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            $this->addFlash('error', 'flash.task_not_found');
            return $this->redirectToRoute('app_taskboard');
        }

        $this->connection->executeStatement('DELETE FROM pomodoro_sessions WHERE task_id = ?', [$id]);
        $this->connection->executeStatement('DELETE FROM tasks WHERE id = ?', [$id]);

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

        $task = $this->connection->fetchAssociative('SELECT * FROM tasks WHERE id = ?', [$taskId]);
        if (!$task || !$this->isOwner($task, $user->getId())) {
            return new JsonResponse(['error' => 'not found'], 404);
        }

        $workMinutes = max(1, min(60, (int) $request->request->get('work_minutes', 25)));
        $breakMinutes = max(1, min(30, (int) $request->request->get('break_minutes', 5)));

        $this->connection->executeStatement(
            'INSERT INTO pomodoro_sessions (task_id, work_minutes, break_minutes, cycles, started_at, completed)
             VALUES (?, ?, ?, 1, NOW(), 0)',
            [$taskId, $workMinutes, $breakMinutes]
        );
        $sessionId = (int) $this->connection->lastInsertId();

        return new JsonResponse(['id' => $sessionId, 'work_minutes' => $workMinutes, 'break_minutes' => $breakMinutes]);
    }

    #[Route('/taskboard/pomodoro/complete/{id}', name: 'app_taskboard_pomodoro_complete', methods: ['POST'])]
    public function completePomodoro(int $id, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $this->connection->executeStatement(
            'UPDATE pomodoro_sessions SET ended_at = NOW(), completed = 1 WHERE id = ?',
            [$id]
        );

        return new JsonResponse(['ok' => true]);
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
                $exists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'TASK_OVERDUE' AND related_topic_id = ? AND is_read = 0",
                    [$userId, $taskId]
                );
                if (!$exists) {
                    $this->connection->executeStatement(
                        "INSERT INTO notifications (user_id, type, title, message, is_read, related_topic_id, created_at) VALUES (?, 'TASK_OVERDUE', ?, ?, 0, ?, NOW())",
                        [$userId, '⚠️ Tâche en retard !', '"' . $title . '" a dépassé sa date limite.', $taskId]
                    );
                }
            } elseif ($dueDate === $today) {
                // Due today — only ONE unread notification per task
                $exists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'TASK_DUE_TODAY' AND related_topic_id = ? AND is_read = 0",
                    [$userId, $taskId]
                );
                if (!$exists) {
                    $this->connection->executeStatement(
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
        $this->connection->executeStatement(
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

        $this->connection->executeStatement(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$user->getId()]
        );

        $referer = $request->headers->get('referer');
        return $this->redirect($referer ?: $this->generateUrl('app_taskboard'));
    }
}
