<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProfileController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2 MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
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

        // User's forum topics (like Facebook posts on profile)
        $forumTopics = $conn->fetchAllAssociative(
            'SELECT ft.id, ft.title, ft.category, ft.is_closed, ft.created_at,
                    (SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = ft.id) as reply_count,
                    (SELECT fp3.content FROM forum_posts fp3 WHERE fp3.topic_id = ft.id ORDER BY fp3.created_at ASC LIMIT 1) as first_post_content,
                    (SELECT fp6.media_files FROM forum_posts fp6 WHERE fp6.topic_id = ft.id AND fp6.media_files IS NOT NULL ORDER BY fp6.created_at ASC LIMIT 1) as first_post_media_files,
                    (SELECT fp4.media_type FROM forum_posts fp4 WHERE fp4.topic_id = ft.id AND fp4.media_path IS NOT NULL ORDER BY fp4.created_at ASC LIMIT 1) as first_post_media_type,
                    (SELECT fp5.media_path FROM forum_posts fp5 WHERE fp5.topic_id = ft.id AND fp5.media_path IS NOT NULL ORDER BY fp5.created_at ASC LIMIT 1) as first_post_media_path,
                    (SELECT COUNT(*) FROM forum_post_reactions fpr WHERE fpr.post_id IN (SELECT fpp.id FROM forum_posts fpp WHERE fpp.topic_id = ft.id) AND fpr.reaction_type = \'like\') as like_count
             FROM forum_topics ft
             WHERE ft.created_by_user_id = ?
             ORDER BY ft.created_at DESC
             LIMIT 20',
            [$userId]
        );

        // Unread notifications
        $unreadNotifications = $conn->fetchAllAssociative(
            'SELECT id, type, title, message, created_at, related_topic_id FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10',
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
            'forumTopics' => $forumTopics,
            'unreadNotifications' => $unreadNotifications,
            'unreadCount' => $unreadCount,
            'profileShareUrl' => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/profile/qr-code', name: 'app_profile_qr', methods: ['GET'])]
    public function qrCode(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user instanceof User) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $size = max(160, min(800, $request->query->getInt('size', 220)));
        $profileUrl = $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($profileUrl)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($size)
                ->margin(10)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $response = new Response($result->getString(), Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
            ]);

            if ($request->query->getBoolean('download')) {
                $response->headers->set('Content-Disposition', 'attachment; filename="learnadapt-profile-qr.png"');
            }

            return $response;
        } catch (\Throwable) {
            return new Response(
                sprintf(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d"><rect width="%1$d" height="%1$d" rx="18" fill="#0f121c"/><rect x="14" y="14" width="%2$d" height="%2$d" rx="10" fill="#ffffff"/><text x="50%%" y="50%%" fill="#0f121c" font-family="Arial, sans-serif" font-size="14" font-weight="700" text-anchor="middle" dominant-baseline="middle">QR unavailable</text></svg>',
                    $size,
                    $size - 28,
                ),
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'image/svg+xml']
            );
        }
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
