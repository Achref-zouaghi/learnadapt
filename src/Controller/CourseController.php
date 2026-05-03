<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\CourseRepository;
use App\Repository\CourseBookmarkRepository;
use App\Repository\CourseNoteRepository;
use App\Repository\CourseRatingRepository;
use App\Repository\CourseCommentRepository;
use App\Repository\CourseFileRepository;
use App\Repository\CourseProgressRepository;
use App\Repository\UserStreakRepository;
use App\SmartCourseBundle\Event\CourseEnrolledEvent;
use App\SmartCourseBundle\Event\CourseViewedEvent;
use App\Service\AdaptiveLearningService;
use App\SmartCourseBundle\Service\AnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CourseController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CourseRepository $courseRepository,
        private readonly CourseBookmarkRepository $bookmarkRepository,
        private readonly CourseNoteRepository $noteRepository,
        private readonly CourseRatingRepository $ratingRepository,
        private readonly CourseCommentRepository $commentRepository,
        private readonly CourseFileRepository $fileRepository,
        private readonly CourseProgressRepository $progressRepository,
        private readonly UserStreakRepository $streakRepository,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly PaginatorInterface $paginator,
        private readonly AnalyticsService $analyticsService,
        private readonly AdaptiveLearningService $adaptiveLearning,
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

    #[Route('/courses', name: 'app_courses')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $level = $request->query->get('level', '');
        $search = $request->query->get('q', '');
        $moduleId = $request->query->get('module', '');

        $allCourses = $this->courseRepository->findFiltered($level, $search, $moduleId ? (int)$moduleId : null);
        $courses = $this->paginator->paginate($allCourses, $request->query->getInt('page', 1), 9);
        $counts = $this->courseRepository->getLevelCounts();
        $modules = $this->courseRepository->getModulesWithCounts();

        $bookmarkedIds = $this->bookmarkRepository->getBookmarkedCourseIds($user->getId());
        $progressMap = $this->progressRepository->getProgressMapForUser($user->getId());

        $ratingRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT course_id, AVG(rating) as avg_rating, COUNT(*) as cnt FROM course_ratings GROUP BY course_id'
        );
        $ratingMap = [];
        foreach ($ratingRows as $rr) {
            $ratingMap[$rr['course_id']] = $rr;
        }

        $streak = $this->streakRepository->findOneByUser($user->getId());
        $platformStats = $this->analyticsService->getGlobalStats();
        $aiProfile = $this->adaptiveLearning->getStudentProfile($user->getId());

        return $this->render('courses/index.html.twig', [
            'courses' => $courses,
            'counts' => $counts,
            'modules' => $modules,
            'currentLevel' => $level,
            'currentModule' => $moduleId,
            'searchQuery' => $search,
            'bookmarkedIds' => $bookmarkedIds,
            'progressMap' => $progressMap,
            'ratingMap' => $ratingMap,
            'streak' => $streak,
            'userId' => $user->getId(),
            'platformStats' => $platformStats,
            'aiProfile' => $aiProfile,
        ]);
    }

    #[Route('/courses/{id}', name: 'app_course_show', requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $course = $this->courseRepository->findWithDetails($id);
        if (!$course) {
            throw $this->createNotFoundException('Course not found.');
        }

        $this->trackCourseAccess($user->getId(), $id);

        $conn = $this->em->getConnection();

        $exercises = $conn->fetchAllAssociative(
            'SELECT e.* FROM exercice e
             INNER JOIN course_exercises ce ON ce.exercise_id = e.id
             WHERE ce.course_id = ?',
            [$id]
        );

        $isBookmarked = (bool) $this->bookmarkRepository->findOneByUserAndCourse($user->getId(), $id);
        $notes = $this->noteRepository->findByUserAndCourse($user->getId(), $id);
        $ratingStats = $this->ratingRepository->getStatsForCourse($id);
        $userRating = $this->ratingRepository->getUserRatingForCourse($user->getId(), $id);
        $reviews = $this->ratingRepository->getReviewsForCourse($id);
        $comments = $this->commentRepository->findByCourse($id);
        $files = $this->fileRepository->findByCourse($id);

        $prerequisites = $conn->fetchAllAssociative(
            'SELECT c.id, c.title, c.level FROM course_prerequisites cp
             JOIN courses c ON cp.prerequisite_course_id = c.id
             WHERE cp.course_id = ?',
            [$id]
        );

        $related = $conn->fetchAllAssociative(
            'SELECT c.id, c.title, c.level, c.description, m.name as module_name
             FROM courses c
             LEFT JOIN modules m ON c.module_id = m.id
             WHERE c.id != ? AND (c.module_id = ? OR c.level = ?)
             ORDER BY RAND() LIMIT 4',
            [$id, $course['module_id'], $course['level']]
        );

        $progress = $this->progressRepository->findOneByUserAndCourse($user->getId(), $id);
        $streak = $this->streakRepository->findOneByUser($user->getId());

        $tags = $conn->fetchAllAssociative(
            'SELECT ct.* FROM course_tags ct
             JOIN course_tag_map ctm ON ct.id = ctm.tag_id
             WHERE ctm.course_id = ?',
            [$id]
        );

        $leaderboard = $this->progressRepository->getLeaderboard($id);

        $readingTime = null;
        if ($course['pdf_path']) {
            $pdfFile = $this->getParameter('kernel.project_dir') . '/' . $course['pdf_path'];
            if (file_exists($pdfFile)) {
                $sizeKb = filesize($pdfFile) / 1024;
                $estPages = max(1, round($sizeKb / 50));
                $readingTime = $estPages * 2;
            }
        }

        return $this->render('courses/show.html.twig', [
            'course' => $course,
            'exercises' => $exercises,
            'isBookmarked' => $isBookmarked,
            'notes' => $notes,
            'ratingStats' => $ratingStats,
            'userRating' => $userRating,
            'reviews' => $reviews,
            'comments' => $comments,
            'files' => $files,
            'prerequisites' => $prerequisites,
            'related' => $related,
            'progress' => $progress,
            'streak' => $streak,
            'tags' => $tags,
            'leaderboard' => $leaderboard,
            'readingTime' => $readingTime,
        ]);
    }

    private function trackCourseAccess(int $userId, int $courseId): void
    {
        $conn = $this->em->getConnection();

        $existing = $conn->fetchAssociative(
            'SELECT id FROM course_progress WHERE user_id = ? AND course_id = ?',
            [$userId, $courseId]
        );
        if ($existing) {
            $conn->executeStatement(
                'UPDATE course_progress SET last_accessed = NOW(), xp_earned = xp_earned + 1 WHERE user_id = ? AND course_id = ?',
                [$userId, $courseId]
            );
            // Dispatch view event for analytics + recommendation engine
            $this->eventDispatcher->dispatch(
                new CourseViewedEvent($userId, $courseId),
                CourseViewedEvent::NAME
            );
        } else {
            $conn->executeStatement(
                'INSERT INTO course_progress (user_id, course_id, progress_percent, xp_earned, last_accessed) VALUES (?, ?, 10, 5, NOW())',
                [$userId, $courseId]
            );
            // First time accessing = enrolment — fetch course title + user email for notification
            $courseRow = $conn->fetchAssociative('SELECT title FROM courses WHERE id = ?', [$courseId]);
            $userRow   = $conn->fetchAssociative('SELECT email FROM users WHERE id = ?', [$userId]);
            $this->eventDispatcher->dispatch(
                new CourseEnrolledEvent(
                    $userId,
                    $courseId,
                    $courseRow['title'] ?? '',
                    $userRow['email'] ?? '',
                ),
                CourseEnrolledEvent::NAME
            );
        }

        $today = date('Y-m-d');
        $streak = $this->streakRepository->findOneByUser($userId);
        if (!$streak) {
            $conn->executeStatement(
                'INSERT INTO user_streaks (user_id, current_streak, longest_streak, last_activity_date) VALUES (?, 1, 1, ?)',
                [$userId, $today]
            );
        } elseif ($streak['last_activity_date'] !== $today) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($streak['last_activity_date'] === $yesterday) {
                $newStreak = $streak['current_streak'] + 1;
                $longest = max($newStreak, $streak['longest_streak']);
                $conn->executeStatement(
                    'UPDATE user_streaks SET current_streak = ?, longest_streak = ?, last_activity_date = ? WHERE user_id = ?',
                    [$newStreak, $longest, $today, $userId]
                );
            } else {
                $conn->executeStatement(
                    'UPDATE user_streaks SET current_streak = 1, last_activity_date = ? WHERE user_id = ?',
                    [$today, $userId]
                );
            }
        }
    }

    #[Route('/courses/{id}/pdf', name: 'app_course_pdf', requirements: ['id' => '\d+'])]
    public function downloadPdf(Request $request, int $id): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $course = $this->courseRepository->find($id);
        if (!$course || !$course->getPdfPath()) {
            throw $this->createNotFoundException('No PDF found for this course.');
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/' . $course->getPdfPath();
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('PDF file not found.');
        }

        return new BinaryFileResponse($filePath);
    }

    #[Route('/courses/{id}/bookmark', name: 'app_course_bookmark', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleBookmark(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $conn = $this->em->getConnection();
        $existing = $this->bookmarkRepository->findOneByUserAndCourse($user->getId(), $id);
        if ($existing) {
            $conn->executeStatement('DELETE FROM course_bookmarks WHERE user_id = ? AND course_id = ?', [$user->getId(), $id]);
            return new JsonResponse(['bookmarked' => false]);
        } else {
            $conn->executeStatement('INSERT INTO course_bookmarks (user_id, course_id, created_at) VALUES (?, ?, NOW())', [$user->getId(), $id]);
            return new JsonResponse(['bookmarked' => true]);
        }
    }

    #[Route('/courses/{id}/notes', name: 'app_course_note_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveNote(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        $noteId = $data['note_id'] ?? null;

        if ($content === '') return new JsonResponse(['error' => 'Content required'], 400);

        $conn = $this->em->getConnection();
        if ($noteId) {
            $conn->executeStatement(
                'UPDATE course_notes SET content = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                [$content, $noteId, $user->getId()]
            );
            return new JsonResponse(['success' => true, 'note_id' => $noteId]);
        } else {
            $conn->executeStatement(
                'INSERT INTO course_notes (user_id, course_id, content, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$user->getId(), $id, $content]
            );
            return new JsonResponse(['success' => true, 'note_id' => (int)$conn->lastInsertId()]);
        }
    }

    #[Route('/courses/{id}/notes/{noteId}/delete', name: 'app_course_note_delete', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function deleteNote(Request $request, int $id, int $noteId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $this->em->getConnection()->executeStatement('DELETE FROM course_notes WHERE id = ? AND user_id = ?', [$noteId, $user->getId()]);
        return new JsonResponse(['success' => true]);
    }

    #[Route('/courses/{id}/rate', name: 'app_course_rate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rateCourse(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $data = json_decode($request->getContent(), true);
        $rating = (int)($data['rating'] ?? 0);
        $review = trim($data['review'] ?? '');

        if ($rating < 1 || $rating > 5) return new JsonResponse(['error' => 'Rating must be 1-5'], 400);

        $conn = $this->em->getConnection();
        $exists = $conn->fetchOne(
            'SELECT id FROM course_ratings WHERE user_id = ? AND course_id = ?',
            [$user->getId(), $id]
        );
        if ($exists) {
            $conn->executeStatement(
                'UPDATE course_ratings SET rating = ?, review = ?, created_at = NOW() WHERE user_id = ? AND course_id = ?',
                [$rating, $review ?: null, $user->getId(), $id]
            );
        } else {
            $conn->executeStatement(
                'INSERT INTO course_ratings (user_id, course_id, rating, review, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$user->getId(), $id, $rating, $review ?: null]
            );
        }

        $conn->executeStatement(
            'UPDATE course_progress SET xp_earned = xp_earned + 10 WHERE user_id = ? AND course_id = ?',
            [$user->getId(), $id]
        );

        $stats = $this->ratingRepository->getStatsForCourse($id);
        return new JsonResponse(['success' => true, 'avg' => round($stats['avg_rating'], 1), 'total' => $stats['total_ratings']]);
    }

    #[Route('/courses/{id}/comments', name: 'app_course_comment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addComment(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        $parentId = $data['parent_id'] ?? null;

        if ($content === '') return new JsonResponse(['error' => 'Content required'], 400);
        if (strlen($content) > 2000) return new JsonResponse(['error' => 'Comment too long'], 400);

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO course_comments (user_id, course_id, parent_id, content, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$user->getId(), $id, $parentId, $content]
        );

        $conn->executeStatement(
            'INSERT INTO course_progress (user_id, course_id, xp_earned, last_accessed) VALUES (?, ?, 3, NOW()) ON DUPLICATE KEY UPDATE xp_earned = xp_earned + 3',
            [$user->getId(), $id]
        );

        return new JsonResponse([
            'success' => true,
            'comment' => [
                'id' => (int)$conn->lastInsertId(),
                'content' => htmlspecialchars($content),
                'full_name' => $user->getFullName(),
                'created_at' => date('M d, Y H:i'),
            ]
        ]);
    }

    #[Route('/courses/{id}/comments/{commentId}/delete', name: 'app_course_comment_delete', requirements: ['id' => '\d+', 'commentId' => '\d+'], methods: ['POST'])]
    public function deleteComment(Request $request, int $id, int $commentId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $this->em->getConnection()->executeStatement('DELETE FROM course_comments WHERE id = ? AND user_id = ?', [$commentId, $user->getId()]);
        return new JsonResponse(['success' => true]);
    }

    #[Route('/courses/{id}/progress', name: 'app_course_progress', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $data = json_decode($request->getContent(), true);
        $percent = min(100, max(0, (int)($data['percent'] ?? 0)));

        $existing = $this->progressRepository->findOneByUserAndCourse($user->getId(), $id);

        $xpBonus = 0;
        $completedAt = null;
        if ($percent >= 100 && (!$existing || !$existing['completed_at'])) {
            $xpBonus = 50;
            $completedAt = date('Y-m-d H:i:s');
        }

        $conn = $this->em->getConnection();
        if ($existing) {
            $sql = 'UPDATE course_progress SET progress_percent = ?, xp_earned = xp_earned + ?, last_accessed = NOW()';
            $params = [$percent, $xpBonus];
            if ($completedAt) {
                $sql .= ', completed_at = ?';
                $params[] = $completedAt;
            }
            $sql .= ' WHERE user_id = ? AND course_id = ?';
            $params[] = $user->getId();
            $params[] = $id;
            $conn->executeStatement($sql, $params);
        } else {
            $conn->executeStatement(
                'INSERT INTO course_progress (user_id, course_id, progress_percent, xp_earned, completed_at, last_accessed) VALUES (?, ?, ?, ?, ?, NOW())',
                [$user->getId(), $id, $percent, 5 + $xpBonus, $completedAt]
            );
        }

        return new JsonResponse(['success' => true, 'percent' => $percent, 'completed' => $percent >= 100]);
    }

    #[Route('/courses/files/{fileId}', name: 'app_course_file_download', requirements: ['fileId' => '\d+'])]
    public function downloadFile(Request $request, int $fileId): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return $this->redirectToRoute('app_login');

        $file = $this->em->getConnection()->fetchAssociative('SELECT * FROM course_files WHERE id = ?', [$fileId]);
        if (!$file) throw $this->createNotFoundException('File not found.');

        $filePath = $this->getParameter('kernel.project_dir') . '/' . $file['file_path'];
        if (!file_exists($filePath)) throw $this->createNotFoundException('File not found on disk.');

        return new BinaryFileResponse($filePath);
    }

    #[Route('/courses/{id}/leaderboard', name: 'app_course_leaderboard', requirements: ['id' => '\d+'])]
    public function leaderboard(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 403);

        $leaderboard = $this->progressRepository->getLeaderboard($id);
        return new JsonResponse(['leaderboard' => $leaderboard]);
    }
}
