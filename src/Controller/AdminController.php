<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const ROLES = ['STUDENT', 'TEACHER', 'PARENT', 'EXPERT', 'ADMIN'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function getAuthenticatedAdmin(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        $user = $this->userRepository->find((int) $auth['id']);
        if (!$user instanceof User || strtoupper($user->getRole()) !== 'ADMIN') {
            return null;
        }
        return $user;
    }

    // ─── DASHBOARD ───────────────────────────────────────────────

    #[Route('/admin', name: 'app_admin')]
    public function dashboard(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        // User stats
        $userStats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total_users,
                SUM(CASE WHEN role = \'STUDENT\' THEN 1 ELSE 0 END) as students,
                SUM(CASE WHEN role = \'TEACHER\' THEN 1 ELSE 0 END) as teachers,
                SUM(CASE WHEN role = \'EXPERT\' THEN 1 ELSE 0 END) as experts,
                SUM(CASE WHEN role = \'PARENT\' THEN 1 ELSE 0 END) as parents,
                SUM(CASE WHEN role = \'ADMIN\' THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_this_week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_this_month
             FROM users'
        );

        // Forum stats
        $forumStats = $this->conn()->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM forum_topics) as total_topics,
                (SELECT COUNT(*) FROM forum_posts) as total_posts,
                (SELECT COUNT(*) FROM forum_topics WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as topics_this_week,
                (SELECT COUNT(*) FROM forum_posts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as posts_this_week'
        );

        // Feedback stats
        $feedbackStats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total_feedback,
                ROUND(AVG(rating), 1) as avg_rating,
                SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as feedback_this_week
             FROM app_feedback'
        );

        // Exercise stats
        $exerciseStats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total_exercises,
                SUM(CASE WHEN level = \'EASY\' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN level = \'MEDIUM\' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN level = \'HARD\' THEN 1 ELSE 0 END) as hard,
                (SELECT COUNT(*) FROM modules) as total_modules,
                (SELECT COUNT(*) FROM courses) as total_courses
             FROM exercises'
        );

        // Recent users
        $recentUsers = $this->conn()->fetchAllAssociative(
            'SELECT id, full_name, email, role, is_active, created_at
             FROM users ORDER BY created_at DESC LIMIT 8'
        );

        // Recent forum topics
        $recentTopics = $this->conn()->fetchAllAssociative(
            'SELECT ft.id, ft.title, ft.category, ft.is_closed, ft.created_at,
                    u.full_name as author_name,
                    (SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = ft.id) as reply_count
             FROM forum_topics ft
             JOIN users u ON ft.created_by_user_id = u.id
             ORDER BY ft.created_at DESC LIMIT 6'
        );

        // Recent feedback
        $recentFeedback = $this->conn()->fetchAllAssociative(
            'SELECT af.id, af.rating, af.comment, af.created_at,
                    u.full_name as author_name
             FROM app_feedback af
             JOIN users u ON af.user_id = u.id
             ORDER BY af.created_at DESC LIMIT 6'
        );

        // User registrations per day (last 14 days)
        $registrationTrend = $this->conn()->fetchAllAssociative(
            'SELECT DATE(created_at) as day, COUNT(*) as count
             FROM users
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC'
        );

        // Subscription / revenue stats
        $revenueStats = $this->conn()->fetchAssociative(
            'SELECT
                COALESCE(SUM(amount), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN started_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\') THEN amount ELSE 0 END), 0) as revenue_this_month,
                COUNT(*) as total_subscriptions,
                SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) as active_subscriptions,
                SUM(CASE WHEN billing_cycle = \'monthly\' AND status = \'active\' THEN 1 ELSE 0 END) as monthly_subs,
                SUM(CASE WHEN billing_cycle = \'annual\' AND status = \'active\' THEN 1 ELSE 0 END) as annual_subs
             FROM user_subscriptions'
        );

        $planBreakdown = $this->conn()->fetchAllAssociative(
            'SELECT
                plan,
                COUNT(*) as subscribers,
                COALESCE(SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END), 0) as active,
                COALESCE(SUM(amount), 0) as revenue
             FROM user_subscriptions
             GROUP BY plan
             ORDER BY revenue DESC'
        );

        $recentSubscriptions = $this->conn()->fetchAllAssociative(
            'SELECT us.plan, us.billing_cycle, us.amount, us.currency, us.status, us.started_at,
                    u.full_name, u.email
             FROM user_subscriptions us
             JOIN users u ON us.user_id = u.id
             ORDER BY us.started_at DESC
             LIMIT 8'
        );

        return $this->render('admin/dashboard.html.twig', [
            'user' => $admin,
            'userStats' => $userStats,
            'forumStats' => $forumStats,
            'feedbackStats' => $feedbackStats,
            'exerciseStats' => $exerciseStats,
            'recentUsers' => $recentUsers,
            'recentTopics' => $recentTopics,
            'recentFeedback' => $recentFeedback,
            'registrationTrend' => $registrationTrend,
            'revenueStats' => $revenueStats,
            'planBreakdown' => $planBreakdown,
            'recentSubscriptions' => $recentSubscriptions,
        ]);
    }

    // ─── USER MANAGEMENT ─────────────────────────────────────────

    #[Route('/admin/users', name: 'app_admin_users')]
    public function users(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $filterRole = $request->query->get('role', '');
        $filterStatus = $request->query->get('status', '');
        $search = trim($request->query->get('q', ''));

        $sql = 'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.created_at, u.phone, u.last_login,
                       (SELECT COUNT(*) FROM forum_posts fp WHERE fp.author_user_id = u.id) as post_count
                FROM users u WHERE 1=1';
        $params = [];

        if ($filterRole && in_array($filterRole, self::ROLES, true)) {
            $sql .= ' AND u.role = ?';
            $params[] = $filterRole;
        }
        if ($filterStatus === 'active') {
            $sql .= ' AND u.is_active = 1';
        } elseif ($filterStatus === 'inactive') {
            $sql .= ' AND u.is_active = 0';
        }
        if ($search !== '') {
            $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' ORDER BY u.created_at DESC';
        $users = $this->conn()->fetchAllAssociative($sql, $params);

        $roleCounts = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN role = \'STUDENT\' THEN 1 ELSE 0 END) as students,
                SUM(CASE WHEN role = \'TEACHER\' THEN 1 ELSE 0 END) as teachers,
                SUM(CASE WHEN role = \'EXPERT\' THEN 1 ELSE 0 END) as experts,
                SUM(CASE WHEN role = \'PARENT\' THEN 1 ELSE 0 END) as parents,
                SUM(CASE WHEN role = \'ADMIN\' THEN 1 ELSE 0 END) as admins
             FROM users'
        );

        return $this->render('admin/users.html.twig', [
            'user' => $admin,
            'users' => $users,
            'roleCounts' => $roleCounts,
            'currentRole' => $filterRole,
            'currentStatus' => $filterStatus,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/admin/users/{id}/toggle-status', name: 'app_admin_user_toggle', methods: ['POST'])]
    public function toggleUserStatus(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $newStatus = !$targetUser->isActive();
        $targetUser->setIsActive($newStatus);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'is_active' => $newStatus]);
    }

    #[Route('/admin/users/{id}/change-role', name: 'app_admin_user_role', methods: ['POST'])]
    public function changeUserRole(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $newRole = strtoupper($data['role'] ?? '');
        if (!in_array($newRole, self::ROLES, true)) {
            return new JsonResponse(['error' => 'Invalid role'], 400);
        }

        $targetUser->setRole($newRole);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'role' => $newRole]);
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        if ($id === $admin->getId()) {
            return new JsonResponse(['error' => 'Cannot delete yourself'], 400);
        }

        $targetUser = $this->userRepository->find($id);
        if (!$targetUser) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        // Delete related data first
        $this->conn()->executeStatement('DELETE FROM notifications WHERE user_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM forum_posts WHERE author_user_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM forum_topics WHERE created_by_user_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM app_feedback WHERE user_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM messages_prives WHERE sender_id = ? OR receiver_id = ?', [$id, $id]);
        $this->conn()->executeStatement('DELETE FROM friend_requests WHERE sender_id = ? OR receiver_id = ?', [$id, $id]);

        $this->entityManager->remove($targetUser);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/users/{id}/update', name: 'app_admin_user_update', methods: ['POST'])]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid data'], 400);
        }

        $this->conn()->executeStatement(
            'UPDATE users SET full_name = ?, email = ?, phone = ?, role = ? WHERE id = ?',
            [
                trim($data['full_name'] ?? ''),
                mb_strtolower(trim($data['email'] ?? '')),
                trim($data['phone'] ?? ''),
                strtoupper($data['role'] ?? 'STUDENT'),
                $id,
            ]
        );

        return new JsonResponse(['success' => true]);
    }

    // ─── FORUM MANAGEMENT ────────────────────────────────────────

    #[Route('/admin/forum', name: 'app_admin_forum')]
    public function forum(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $search = trim($request->query->get('q', ''));
        $filterCategory = $request->query->get('category', '');

        $sql = 'SELECT ft.*, u.full_name as author_name, u.role as author_role,
                       (SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = ft.id) as reply_count,
                       (SELECT MAX(fp2.created_at) FROM forum_posts fp2 WHERE fp2.topic_id = ft.id) as last_reply_at
                FROM forum_topics ft
                JOIN users u ON ft.created_by_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($filterCategory) {
            $sql .= ' AND ft.category = ?';
            $params[] = $filterCategory;
        }
        if ($search !== '') {
            $sql .= ' AND (ft.title LIKE ? OR u.full_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' ORDER BY ft.created_at DESC';
        $topics = $this->conn()->fetchAllAssociative($sql, $params);

        $categoryCounts = $this->conn()->fetchAllAssociative(
            'SELECT category, COUNT(*) as count FROM forum_topics GROUP BY category'
        );

        $stats = $this->conn()->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM forum_topics) as total_topics,
                (SELECT COUNT(*) FROM forum_posts) as total_posts,
                (SELECT COUNT(*) FROM forum_topics WHERE is_closed = 1) as closed_topics,
                (SELECT COUNT(*) FROM forum_topics WHERE is_closed = 0) as open_topics'
        );

        return $this->render('admin/forum.html.twig', [
            'user' => $admin,
            'topics' => $topics,
            'categoryCounts' => $categoryCounts,
            'stats' => $stats,
            'currentCategory' => $filterCategory,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/admin/forum/{id}/toggle-close', name: 'app_admin_forum_toggle', methods: ['POST'])]
    public function toggleTopicClose(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $topic = $this->conn()->fetchAssociative('SELECT * FROM forum_topics WHERE id = ?', [$id]);
        if (!$topic) {
            return new JsonResponse(['error' => 'Topic not found'], 404);
        }

        $newClosed = $topic['is_closed'] ? 0 : 1;
        $this->conn()->executeStatement('UPDATE forum_topics SET is_closed = ? WHERE id = ?', [$newClosed, $id]);

        return new JsonResponse(['success' => true, 'is_closed' => (bool) $newClosed]);
    }

    #[Route('/admin/forum/{id}/delete', name: 'app_admin_forum_delete', methods: ['POST'])]
    public function deleteForumTopic(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $this->conn()->executeStatement('DELETE FROM forum_posts WHERE topic_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM forum_topics WHERE id = ?', [$id]);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/forum/{id}/update', name: 'app_admin_forum_update', methods: ['POST'])]
    public function updateForumTopic(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid data'], 400);
        }

        $this->conn()->executeStatement(
            'UPDATE forum_topics SET title = ?, category = ? WHERE id = ?',
            [trim($data['title'] ?? ''), trim($data['category'] ?? ''), $id]
        );

        return new JsonResponse(['success' => true]);
    }

    // ─── FEEDBACK MANAGEMENT ─────────────────────────────────────

    #[Route('/admin/feedback', name: 'app_admin_feedback')]
    public function feedback(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $filterRating = $request->query->get('rating', '');

        $sql = 'SELECT af.*, u.full_name as author_name, u.email as author_email,
                       u.role as author_role
                FROM app_feedback af
                JOIN users u ON af.user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($filterRating !== '' && is_numeric($filterRating)) {
            $sql .= ' AND af.rating = ?';
            $params[] = (int) $filterRating;
        }

        $sql .= ' ORDER BY af.created_at DESC';
        $feedbacks = $this->conn()->fetchAllAssociative($sql, $params);

        $stats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                ROUND(AVG(rating), 1) as avg_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
             FROM app_feedback'
        );

        return $this->render('admin/feedback.html.twig', [
            'user' => $admin,
            'feedbacks' => $feedbacks,
            'stats' => $stats,
            'currentRating' => $filterRating,
        ]);
    }

    #[Route('/admin/feedback/{id}/delete', name: 'app_admin_feedback_delete', methods: ['POST'])]
    public function deleteFeedback(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $this->conn()->executeStatement('DELETE FROM feedback_reactions WHERE feedback_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM app_feedback WHERE id = ?', [$id]);

        return new JsonResponse(['success' => true]);
    }

    // ─── EXERCISE MANAGEMENT ─────────────────────────────────────

    #[Route('/admin/exercises', name: 'app_admin_exercises')]
    public function exercises(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $filterLevel = $request->query->get('level', '');
        $filterModule = $request->query->get('module', '');
        $search = trim($request->query->get('q', ''));

        $sql = 'SELECT e.*, m.name as module_name, u.full_name as teacher_name
                FROM exercises e
                LEFT JOIN modules m ON e.module_id = m.id
                LEFT JOIN users u ON e.teacher_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($filterLevel && in_array($filterLevel, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $sql .= ' AND e.level = ?';
            $params[] = $filterLevel;
        }
        if ($filterModule !== '') {
            $sql .= ' AND e.module_id = ?';
            $params[] = (int) $filterModule;
        }
        if ($search !== '') {
            $sql .= ' AND (e.title LIKE ? OR e.description LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' ORDER BY e.created_at DESC';
        $exercises = $this->conn()->fetchAllAssociative($sql, $params);

        $modules = $this->conn()->fetchAllAssociative(
            'SELECT m.*, (SELECT COUNT(*) FROM exercises ex WHERE ex.module_id = m.id) as exercise_count
             FROM modules m ORDER BY m.name ASC'
        );

        $stats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN level = \'EASY\' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN level = \'MEDIUM\' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN level = \'HARD\' THEN 1 ELSE 0 END) as hard
             FROM exercises'
        );

        return $this->render('admin/exercises.html.twig', [
            'user' => $admin,
            'exercises' => $exercises,
            'modules' => $modules,
            'stats' => $stats,
            'currentLevel' => $filterLevel,
            'currentModule' => $filterModule,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/admin/exercises/{id}/delete', name: 'app_admin_exercise_delete', methods: ['POST'])]
    public function deleteExercise(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        // Get pdf path to delete file
        $exercise = $this->conn()->fetchAssociative('SELECT pdf_path FROM exercises WHERE id = ?', [$id]);
        if ($exercise && $exercise['pdf_path']) {
            $filePath = $this->getParameter('kernel.project_dir') . '/' . $exercise['pdf_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $this->conn()->executeStatement('DELETE FROM exercises WHERE id = ?', [$id]);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/exercises/{id}/update', name: 'app_admin_exercise_update', methods: ['POST'])]
    public function updateExercise(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid data'], 400);
        }

        $level = strtoupper($data['level'] ?? '');
        if (!in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            return new JsonResponse(['error' => 'Invalid level'], 400);
        }

        $this->conn()->executeStatement(
            'UPDATE exercises SET title = ?, description = ?, level = ?, module_id = ? WHERE id = ?',
            [
                trim($data['title'] ?? ''),
                trim($data['description'] ?? ''),
                $level,
                !empty($data['module_id']) ? (int) $data['module_id'] : null,
                $id,
            ]
        );

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/exercises/{id}/upload-pdf', name: 'app_admin_exercise_upload_pdf', methods: ['POST'])]
    public function uploadExercisePdf(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $file = $request->files->get('pdf');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        if ($file->getMimeType() !== 'application/pdf') {
            return new JsonResponse(['error' => 'Only PDF files are allowed'], 400);
        }

        if ($file->getSize() > 20 * 1024 * 1024) {
            return new JsonResponse(['error' => 'File too large (max 20MB)'], 400);
        }

        // Remove old PDF if exists
        $existing = $this->conn()->fetchAssociative('SELECT pdf_path FROM exercises WHERE id = ?', [$id]);
        if ($existing && $existing['pdf_path']) {
            $oldPath = $this->getParameter('kernel.project_dir') . '/' . $existing['pdf_path'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/exercises';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $file->move($uploadDir, $safeName);

        $pdfPath = 'var/uploads/exercises/' . $safeName;

        $this->conn()->executeStatement(
            'UPDATE exercises SET pdf_path = ?, pdf_original_name = ?, pdf_size_bytes = ? WHERE id = ?',
            [$pdfPath, $originalName, filesize($uploadDir . '/' . $safeName), $id]
        );

        return new JsonResponse(['success' => true, 'filename' => $originalName]);
    }

    #[Route('/admin/exercises/{id}/upload-video', name: 'app_admin_exercise_upload_video', methods: ['POST'])]
    public function uploadExerciseVideo(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $file = $request->files->get('video');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        $allowedMimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return new JsonResponse(['error' => 'Only video files are allowed (mp4, webm, ogg, avi, mov)'], 400);
        }

        if ($file->getSize() > 500 * 1024 * 1024) {
            return new JsonResponse(['error' => 'File too large (max 500MB)'], 400);
        }

        // Remove old video if exists
        $existing = $this->conn()->fetchAssociative('SELECT video_path FROM exercises WHERE id = ?', [$id]);
        if ($existing && $existing['video_path']) {
            $oldPath = $this->getParameter('kernel.project_dir') . '/' . $existing['video_path'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/exercises';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $file->move($uploadDir, $safeName);

        $videoPath = 'var/uploads/exercises/' . $safeName;

        $this->conn()->executeStatement(
            'UPDATE exercises SET video_path = ?, video_original_name = ? WHERE id = ?',
            [$videoPath, $originalName, $id]
        );

        return new JsonResponse(['success' => true, 'filename' => $originalName]);
    }

    #[Route('/admin/exercises/create', name: 'app_admin_exercise_create', methods: ['POST'])]
    public function createExercise(Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $title = trim($request->request->get('title', ''));
        $description = trim($request->request->get('description', ''));
        $level = strtoupper($request->request->get('level', 'EASY'));
        $moduleId = $request->request->get('module_id');

        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }
        if (!in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            return new JsonResponse(['error' => 'Invalid level'], 400);
        }

        $pdfPath = null;
        $pdfOriginalName = null;
        $pdfSize = null;

        $file = $request->files->get('pdf');
        if ($file) {
            if ($file->getMimeType() !== 'application/pdf') {
                return new JsonResponse(['error' => 'Only PDF files are allowed'], 400);
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/exercises';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $originalName = $file->getClientOriginalName();
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $file->move($uploadDir, $safeName);

            $pdfPath = 'var/uploads/exercises/' . $safeName;
            $pdfOriginalName = $originalName;
            $pdfSize = filesize($uploadDir . '/' . $safeName);
        }

        $this->conn()->executeStatement(
            'INSERT INTO exercises (title, description, level, module_id, teacher_user_id, pdf_path, pdf_original_name, pdf_size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $title,
                $description,
                $level,
                !empty($moduleId) ? (int) $moduleId : null,
                $admin->getId(),
                $pdfPath,
                $pdfOriginalName,
                $pdfSize,
            ]
        );

        return new JsonResponse(['success' => true]);
    }

    // ─── QUIZZES ─────────────────────────────────────────────────

    #[Route('/admin/quizzes', name: 'app_admin_quizzes')]
    public function quizzes(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $quizzes = $this->conn()->fetchAllAssociative(
            'SELECT q.*,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) as question_count,
                    (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) as attempt_count,
                    (SELECT ROUND(AVG(qa2.score_percent), 1) FROM quiz_attempts qa2 WHERE qa2.quiz_id = q.id AND qa2.finished_at IS NOT NULL) as avg_score,
                    (SELECT u.full_name FROM users u WHERE u.id = q.created_by) as creator_name
             FROM diagnostic_quizzes q
             ORDER BY q.created_at DESC'
        );

        return $this->render('admin/quizzes.html.twig', [
            'quizzes' => $quizzes,
        ]);
    }

    #[Route('/admin/quizzes/create', name: 'app_admin_quiz_create', methods: ['POST'])]
    public function createQuiz(Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $timeLimit = !empty($data['time_limit_minutes']) ? (int) $data['time_limit_minutes'] : null;

        $this->conn()->executeStatement(
            'INSERT INTO diagnostic_quizzes (title, description, is_active, time_limit_minutes, created_by, created_at) VALUES (?, ?, 1, ?, ?, NOW())',
            [$title, trim($data['description'] ?? ''), $timeLimit, $admin->getId()]
        );

        return new JsonResponse(['success' => true, 'id' => (int) $this->conn()->lastInsertId()]);
    }

    #[Route('/admin/quizzes/{id}/update', name: 'app_admin_quiz_update', methods: ['POST'])]
    public function updateQuiz(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $timeLimit = !empty($data['time_limit_minutes']) ? (int) $data['time_limit_minutes'] : null;

        $this->conn()->executeStatement(
            'UPDATE diagnostic_quizzes SET title = ?, description = ?, time_limit_minutes = ?, is_active = ? WHERE id = ?',
            [$title, trim($data['description'] ?? ''), $timeLimit, (int) ($data['is_active'] ?? 1), $id]
        );

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/quizzes/{id}/toggle', name: 'app_admin_quiz_toggle', methods: ['POST'])]
    public function toggleQuiz(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $this->conn()->executeStatement(
            'UPDATE diagnostic_quizzes SET is_active = NOT is_active WHERE id = ?',
            [$id]
        );

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/quizzes/{id}/delete', name: 'app_admin_quiz_delete', methods: ['POST'])]
    public function deleteQuiz(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        // Delete answers, attempts, questions, then quiz
        $this->conn()->executeStatement(
            'DELETE qa FROM quiz_answers qa JOIN quiz_attempts qat ON qat.id = qa.attempt_id WHERE qat.quiz_id = ?',
            [$id]
        );
        $this->conn()->executeStatement('DELETE FROM quiz_attempts WHERE quiz_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM quiz_questions WHERE quiz_id = ?', [$id]);
        $this->conn()->executeStatement('DELETE FROM diagnostic_quizzes WHERE id = ?', [$id]);

        return new JsonResponse(['success' => true]);
    }

    // ─── QUIZ QUESTIONS ──────────────────────────────────────────

    #[Route('/admin/quizzes/{id}/questions', name: 'app_admin_quiz_questions')]
    public function quizQuestions(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $questions = $this->conn()->fetchAllAssociative(
            'SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC',
            [$id]
        );

        return new JsonResponse(['questions' => $questions]);
    }

    #[Route('/admin/quizzes/{id}/questions/add', name: 'app_admin_quiz_question_add', methods: ['POST'])]
    public function addQuestion(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $type = $data['question_type'] ?? 'MCQ';
        $prompt = trim($data['prompt'] ?? '');

        if ($prompt === '') {
            return new JsonResponse(['error' => 'Question prompt is required'], 400);
        }

        if (!in_array($type, ['MCQ', 'TRUE_FALSE', 'SHORT_TEXT'], true)) {
            return new JsonResponse(['error' => 'Invalid question type'], 400);
        }

        $difficulty = strtoupper($data['difficulty'] ?? 'EASY');
        if (!in_array($difficulty, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $difficulty = 'EASY';
        }

        $this->conn()->executeStatement(
            'INSERT INTO quiz_questions (quiz_id, question_type, prompt, option_a, option_b, option_c, option_d, correct_option, correct_bool, correct_text, points, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $type,
                $prompt,
                trim($data['option_a'] ?? '') ?: null,
                trim($data['option_b'] ?? '') ?: null,
                trim($data['option_c'] ?? '') ?: null,
                trim($data['option_d'] ?? '') ?: null,
                !empty($data['correct_option']) ? strtoupper($data['correct_option']) : null,
                isset($data['correct_bool']) && $data['correct_bool'] !== '' ? (int) $data['correct_bool'] : null,
                trim($data['correct_text'] ?? '') ?: null,
                max(1, (int) ($data['points'] ?? 1)),
                $difficulty,
            ]
        );

        return new JsonResponse(['success' => true, 'id' => (int) $this->conn()->lastInsertId()]);
    }

    #[Route('/admin/quizzes/questions/{qid}/delete', name: 'app_admin_quiz_question_delete', methods: ['POST'])]
    public function deleteQuestion(int $qid, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $this->conn()->executeStatement('DELETE FROM quiz_answers WHERE question_id = ?', [$qid]);
        $this->conn()->executeStatement('DELETE FROM quiz_questions WHERE id = ?', [$qid]);

        return new JsonResponse(['success' => true]);
    }

    // ─── COURSES ─────────────────────────────────────────────────

    #[Route('/admin/courses', name: 'app_admin_courses')]
    public function courses(Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return $this->redirectToRoute('app_login');
        }

        $filterLevel = $request->query->get('level', '');
        $search = trim($request->query->get('q', ''));

        $sql = 'SELECT c.*, m.name as module_name, u.full_name as teacher_name
                FROM courses c
                LEFT JOIN modules m ON c.module_id = m.id
                LEFT JOIN users u ON c.teacher_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($filterLevel && in_array($filterLevel, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $sql .= ' AND c.level = ?';
            $params[] = $filterLevel;
        }
        if ($search !== '') {
            $sql .= ' AND (c.title LIKE ? OR c.description LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' ORDER BY c.created_at DESC';
        $courses = $this->conn()->fetchAllAssociative($sql, $params);

        $modules = $this->conn()->fetchAllAssociative(
            'SELECT m.*, (SELECT COUNT(*) FROM courses co WHERE co.module_id = m.id) as course_count
             FROM modules m ORDER BY m.name ASC'
        );

        $teachers = $this->conn()->fetchAllAssociative(
            "SELECT id, full_name FROM users WHERE role IN ('TEACHER','ADMIN') ORDER BY full_name ASC"
        );

        $stats = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN level = \'EASY\' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN level = \'MEDIUM\' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN level = \'HARD\' THEN 1 ELSE 0 END) as hard
             FROM courses'
        );

        return $this->render('admin/courses.html.twig', [
            'user' => $admin,
            'courses' => $courses,
            'modules' => $modules,
            'teachers' => $teachers,
            'stats' => $stats,
            'currentLevel' => $filterLevel,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/admin/courses/create', name: 'app_admin_course_create', methods: ['POST'])]
    public function createCourse(Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $title = trim($request->request->get('title', ''));
        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $level = $request->request->get('level', 'EASY');
        if (!in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $level = 'EASY';
        }

        $moduleId = $request->request->get('module_id') ?: null;
        $teacherId = $request->request->get('teacher_id') ?: null;
        $description = $request->request->get('description');
        $videoUrl = trim($request->request->get('video_url', '')) ?: null;

        $pdfPath = null;
        $file = $request->files->get('pdf');
        if ($file) {
            if ($file->getMimeType() !== 'application/pdf') {
                return new JsonResponse(['error' => 'Only PDF files are allowed'], 400);
            }
            if ($file->getSize() > 20 * 1024 * 1024) {
                return new JsonResponse(['error' => 'File too large (max 20MB)'], 400);
            }
            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/courses';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $file->move($uploadDir, $safeName);
            $pdfPath = 'var/uploads/courses/' . $safeName;
        }

        $this->conn()->executeStatement(
            'INSERT INTO courses (title, description, level, module_id, teacher_user_id, pdf_path, video_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $title,
                $description,
                $level,
                $moduleId ? (int)$moduleId : null,
                $teacherId ? (int)$teacherId : null,
                $pdfPath,
                $videoUrl,
            ]
        );

        $courseId = (int) $this->conn()->lastInsertId();

        // Handle additional PDF files
        $extraFiles = $request->files->get('extra_pdfs');
        if ($extraFiles) {
            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/courses';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $order = 0;
            foreach ($extraFiles as $ef) {
                if ($ef && $ef->getMimeType() === 'application/pdf' && $ef->getSize() <= 20 * 1024 * 1024) {
                    $origName = $ef->getClientOriginalName();
                    $safeName = time() . '_' . $order . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                    $ef->move($uploadDir, $safeName);
                    $this->conn()->executeStatement(
                        'INSERT INTO course_files (course_id, title, file_path, original_name, file_size, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [$courseId, pathinfo($origName, PATHINFO_FILENAME), 'var/uploads/courses/' . $safeName, $origName, filesize($uploadDir . '/' . $safeName), $order]
                    );
                    $order++;
                }
            }
        }

        // Handle tags
        $tagsStr = trim($request->request->get('tags', ''));
        if ($tagsStr !== '') {
            $tagNames = array_filter(array_map('trim', explode(',', $tagsStr)));
            foreach ($tagNames as $tagName) {
                $this->conn()->executeStatement('INSERT IGNORE INTO course_tags (name) VALUES (?)', [$tagName]);
                $tagId = $this->conn()->fetchOne('SELECT id FROM course_tags WHERE name = ?', [$tagName]);
                if ($tagId) {
                    $this->conn()->executeStatement('INSERT IGNORE INTO course_tag_map (course_id, tag_id) VALUES (?, ?)', [$courseId, $tagId]);
                }
            }
        }

        return new JsonResponse(['success' => true, 'id' => $courseId]);
    }

    #[Route('/admin/courses/{id}/update', name: 'app_admin_course_update', methods: ['POST'])]
    public function updateCourse(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $title = trim($request->request->get('title', ''));
        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $level = $request->request->get('level', 'EASY');
        if (!in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $level = 'EASY';
        }

        $moduleId = $request->request->get('module_id') ?: null;
        $teacherId = $request->request->get('teacher_id') ?: null;
        $description = $request->request->get('description');
        $videoUrl = trim($request->request->get('video_url', '')) ?: null;

        $file = $request->files->get('pdf');
        if ($file) {
            if ($file->getMimeType() !== 'application/pdf') {
                return new JsonResponse(['error' => 'Only PDF files are allowed'], 400);
            }
            if ($file->getSize() > 20 * 1024 * 1024) {
                return new JsonResponse(['error' => 'File too large (max 20MB)'], 400);
            }

            // Remove old PDF
            $existing = $this->conn()->fetchAssociative('SELECT pdf_path FROM courses WHERE id = ?', [$id]);
            if ($existing && $existing['pdf_path']) {
                $oldPath = $this->getParameter('kernel.project_dir') . '/' . $existing['pdf_path'];
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/courses';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $file->move($uploadDir, $safeName);
            $pdfPath = 'var/uploads/courses/' . $safeName;

            $this->conn()->executeStatement(
                'UPDATE courses SET title = ?, description = ?, level = ?, module_id = ?, teacher_user_id = ?, pdf_path = ?, video_url = ?, updated_at = NOW() WHERE id = ?',
                [$title, $description, $level, $moduleId ? (int)$moduleId : null, $teacherId ? (int)$teacherId : null, $pdfPath, $videoUrl, $id]
            );
        } else {
            $this->conn()->executeStatement(
                'UPDATE courses SET title = ?, description = ?, level = ?, module_id = ?, teacher_user_id = ?, video_url = ?, updated_at = NOW() WHERE id = ?',
                [$title, $description, $level, $moduleId ? (int)$moduleId : null, $teacherId ? (int)$teacherId : null, $videoUrl, $id]
            );
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/courses/{id}/delete-pdf', name: 'app_admin_course_delete_pdf', methods: ['POST'])]
    public function deleteCoursePdf(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $existing = $this->conn()->fetchAssociative('SELECT pdf_path FROM courses WHERE id = ?', [$id]);
        if ($existing && $existing['pdf_path']) {
            $oldPath = $this->getParameter('kernel.project_dir') . '/' . $existing['pdf_path'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
            $this->conn()->executeStatement('UPDATE courses SET pdf_path = NULL WHERE id = ?', [$id]);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/courses/{id}/pdf', name: 'app_admin_course_pdf', methods: ['GET'])]
    public function serveCoursePdf(int $id, Request $request): Response
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new Response('Unauthorized', 403);
        }

        $course = $this->conn()->fetchAssociative('SELECT pdf_path FROM courses WHERE id = ?', [$id]);
        if (!$course || !$course['pdf_path']) {
            return new Response('No PDF found', 404);
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/' . $course['pdf_path'];
        if (!file_exists($filePath)) {
            return new Response('File not found', 404);
        }

        return new BinaryFileResponse($filePath);
    }

    #[Route('/admin/courses/{id}/delete', name: 'app_admin_course_delete', methods: ['POST'])]
    public function deleteCourse(int $id, Request $request): JsonResponse
    {
        $admin = $this->getAuthenticatedAdmin($request);
        if (!$admin) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        // Remove old PDF file if exists
        $existing = $this->conn()->fetchAssociative('SELECT pdf_path FROM courses WHERE id = ?', [$id]);
        if ($existing && $existing['pdf_path']) {
            $oldPath = $this->getParameter('kernel.project_dir') . '/' . $existing['pdf_path'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Remove course_exercises join entries first
        $this->conn()->executeStatement('DELETE FROM course_exercises WHERE course_id = ?', [$id]);
        // Unlink tasks
        $this->conn()->executeStatement('UPDATE tasks SET linked_course_id = NULL WHERE linked_course_id = ?', [$id]);
        // Delete the course
        $this->conn()->executeStatement('DELETE FROM courses WHERE id = ?', [$id]);

        return new JsonResponse(['success' => true]);
    }
}
