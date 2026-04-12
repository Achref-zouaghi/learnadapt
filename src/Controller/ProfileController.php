<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProfileController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2 MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $userId = $user->getId();
        $conn = $this->entityManager->getConnection();

        // Study stats
        $totalPomodoroMinutes = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(wp.pomodoro_minutes), 0) FROM weekly_progress wp WHERE wp.student_user_id = ?',
            [$userId]
        );
        $tasksCompleted = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM tasks t WHERE t.created_by_teacher_id = ? AND t.status = ?',
            [$userId, 'done']
        );
        $quizAvgScore = (float) $conn->fetchOne(
            'SELECT COALESCE(AVG(qa.score_percent), 0) FROM quiz_attempts qa WHERE qa.student_user_id = ?',
            [$userId]
        );
        $forumPostsCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM forum_posts fp WHERE fp.author_user_id = ?',
            [$userId]
        );

        // Current level
        $currentLevel = $conn->fetchAssociative(
            'SELECT current_level, last_score_percent FROM student_levels WHERE student_user_id = ? ORDER BY updated_at DESC LIMIT 1',
            [$userId]
        );

        // Quiz attempts history
        $quizAttempts = $conn->fetchAllAssociative(
            'SELECT qa.score_percent, qa.level_result, qa.earned_points, qa.total_points, qa.started_at, qa.finished_at,
                    dq.title as quiz_title
             FROM quiz_attempts qa
             LEFT JOIN diagnostic_quizzes dq ON qa.quiz_id = dq.id
             WHERE qa.student_user_id = ?
             ORDER BY qa.started_at DESC
             LIMIT 10',
            [$userId]
        );

        // Recent activity timeline (union of quiz attempts, forum posts, tasks)
        $recentActivity = $conn->fetchAllAssociative(
            '(SELECT \'quiz\' as type, dq.title as title, qa.score_percent as detail, qa.started_at as created_at
              FROM quiz_attempts qa LEFT JOIN diagnostic_quizzes dq ON qa.quiz_id = dq.id WHERE qa.student_user_id = ?)
             UNION ALL
             (SELECT \'post\' as type, ft.title as title, NULL as detail, fp.created_at as created_at
              FROM forum_posts fp LEFT JOIN forum_topics ft ON fp.topic_id = ft.id WHERE fp.author_user_id = ?)
             UNION ALL
             (SELECT \'task\' as type, t.title as title, t.status as detail, t.updated_at as created_at
              FROM tasks t WHERE t.created_by_teacher_id = ? AND t.status = \'done\')
             ORDER BY created_at DESC
             LIMIT 8',
            [$userId, $userId, $userId]
        );

        // Courses
        $courses = $conn->fetchAllAssociative(
            'SELECT c.id, c.title, c.description, c.level, c.created_at,
                    m.name as module_name,
                    (SELECT COUNT(*) FROM course_exercises ce WHERE ce.course_id = c.id) as exercise_count
             FROM courses c
             LEFT JOIN modules m ON c.module_id = m.id
             WHERE c.teacher_user_id = ?
             ORDER BY c.created_at DESC',
            [$userId]
        );

        // Unread notifications
        $unreadNotifications = $conn->fetchAllAssociative(
            'SELECT id, type, title, message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10',
            [$userId]
        );
        $unreadCount = count($unreadNotifications);

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'totalPomodoroMinutes' => $totalPomodoroMinutes,
            'tasksCompleted' => $tasksCompleted,
            'quizAvgScore' => round($quizAvgScore, 1),
            'forumPostsCount' => $forumPostsCount,
            'currentLevel' => $currentLevel ?: null,
            'quizAttempts' => $quizAttempts,
            'recentActivity' => $recentActivity,
            'courses' => $courses,
            'unreadNotifications' => $unreadNotifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    #[Route('/profile/upload-avatar', name: 'app_profile_upload_avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $file = $request->files->get('avatar');

        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Please select a valid image file.');
            return $this->redirectToRoute('app_profile');
        }

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            $this->addFlash('error', 'Image must be under 2 MB.');
            return $this->redirectToRoute('app_profile');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $this->addFlash('error', 'Only JPEG, PNG, GIF and WebP images are allowed.');
            return $this->redirectToRoute('app_profile');
        }

        $data = file_get_contents($file->getPathname());
        $base64 = 'data:' . $mime . ';base64,' . base64_encode($data);

        $user->setAvatar_base64($base64);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', 'Profile photo updated.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/upload-banner', name: 'app_profile_upload_banner', methods: ['POST'])]
    public function uploadBanner(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $file = $request->files->get('banner');

        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Please select a valid image file.');
            return $this->redirectToRoute('app_profile');
        }

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            $this->addFlash('error', 'Image must be under 2 MB.');
            return $this->redirectToRoute('app_profile');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $this->addFlash('error', 'Only JPEG, PNG, GIF and WebP images are allowed.');
            return $this->redirectToRoute('app_profile');
        }

        $data = file_get_contents($file->getPathname());
        $base64 = 'data:' . $mime . ';base64,' . base64_encode($data);

        $user->setBanner_base64($base64);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', 'Banner updated.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/update-bio', name: 'app_profile_update_bio', methods: ['POST'])]
    public function updateBio(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $bio = trim((string) $request->request->get('bio', ''));

        if (mb_strlen($bio) > 500) {
            $this->addFlash('error', 'Bio must be 500 characters or less.');
            return $this->redirectToRoute('app_profile');
        }

        $user->setBio($bio === '' ? null : $bio);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', 'Bio updated.');
        return $this->redirectToRoute('app_profile');
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);

        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }

        return $this->userRepository->find((int) $auth['id']);
    }
}
