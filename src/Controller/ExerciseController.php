<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ExerciseController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function ensureExerciseCorrectionColumns(): void
    {
        $cols = array_column($this->conn()->fetchAllAssociative('SHOW COLUMNS FROM exercises'), 'Field');
        if (!in_array('correction_pdf_path', $cols, true)) {
            $this->conn()->executeStatement('ALTER TABLE exercises ADD COLUMN correction_pdf_path VARCHAR(500) DEFAULT NULL');
        }
        if (!in_array('correction_pdf_original_name', $cols, true)) {
            $this->conn()->executeStatement('ALTER TABLE exercises ADD COLUMN correction_pdf_original_name VARCHAR(255) DEFAULT NULL');
        }
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        return $this->userRepository->find((int) $auth['id']);
    }

    #[Route('/exercises', name: 'app_exercises')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $level = $request->query->get('level', '');
        $search = $request->query->get('q', '');
        $moduleId = $request->query->get('module', '');

        $sql = 'SELECT e.*, m.name as module_name, u.full_name as teacher_name
                FROM exercises e
                LEFT JOIN modules m ON e.module_id = m.id
                LEFT JOIN users u ON e.teacher_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($level && in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $sql .= ' AND e.level = ?';
            $params[] = $level;
        }
        if ($moduleId !== '') {
            $sql .= ' AND e.module_id = ?';
            $params[] = (int) $moduleId;
        }
        if ($search) {
            $sql .= ' AND (e.title LIKE ? OR e.description LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY e.created_at DESC';

        $exercises = $this->conn()->fetchAllAssociative($sql, $params);

        $counts = $this->conn()->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN level = \'EASY\' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN level = \'MEDIUM\' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN level = \'HARD\' THEN 1 ELSE 0 END) as hard
             FROM exercises'
        );

        $modules = $this->conn()->fetchAllAssociative(
            'SELECT m.id, m.name, COUNT(e.id) as exercise_count
             FROM modules m
             INNER JOIN exercises e ON e.module_id = m.id
             GROUP BY m.id, m.name
             ORDER BY m.name'
        );

        return $this->render('exercises/index.html.twig', [
            'exercises' => $exercises,
            'counts' => $counts,
            'modules' => $modules,
            'currentLevel' => $level,
            'currentModule' => $moduleId,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/exercises/{id}', name: 'app_exercise_view', requirements: ['id' => '\d+'])]
    public function view(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $this->ensureExerciseCorrectionColumns();

        $exercise = $this->conn()->fetchAssociative(
            'SELECT e.*, m.name as module_name, u.full_name as teacher_name
             FROM exercises e
             LEFT JOIN modules m ON e.module_id = m.id
             LEFT JOIN users u ON e.teacher_user_id = u.id
             WHERE e.id = ?',
            [$id]
        );

        if (!$exercise) {
            return $this->redirectToRoute('app_exercises');
        }

        // Track when user first opened this exercise (for 10-min correction lock)
        $sessionKey = 'exercise_opened_' . $id;
        if (!$request->getSession()->has($sessionKey)) {
            $request->getSession()->set($sessionKey, time());
        }
        $openedAt = $request->getSession()->get($sessionKey);

        return $this->render('exercises/view.html.twig', [
            'exercise' => $exercise,
            'user' => $user,
            'openedAt' => $openedAt,
        ]);
    }

    #[Route('/exercises/{id}/correction-pdf', name: 'app_exercise_correction_pdf', requirements: ['id' => '\d+'])]
    public function viewCorrectionPdf(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Enforce 10-minute lock server-side
        $sessionKey = 'exercise_opened_' . $id;
        $openedAt = $request->getSession()->get($sessionKey);
        if (!$openedAt || (time() - $openedAt) < 600) {
            $remaining = $openedAt ? (600 - (time() - $openedAt)) : 600;
            $mins = (int) ceil($remaining / 60);
            throw $this->createAccessDeniedException(
                'The correction is locked for 10 minutes after opening the exercise. ' . $mins . ' min remaining.'
            );
        }

        $this->ensureExerciseCorrectionColumns();
        $exercise = $this->conn()->fetchAssociative(
            'SELECT correction_pdf_path, correction_pdf_original_name FROM exercises WHERE id = ?',
            [$id]
        );

        if (!$exercise || !$exercise['correction_pdf_path']) {
            throw $this->createNotFoundException('No correction PDF attached to this exercise.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/' . $exercise['correction_pdf_path'];
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Correction file not found on server.');
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
            $exercise['correction_pdf_original_name'] ?: basename($filePath)
        );
        return $response;
    }

    #[Route('/exercises/{id}/video', name: 'app_exercise_view_video', requirements: ['id' => '\d+'])]
    public function viewVideo(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $exercise = $this->conn()->fetchAssociative(
            'SELECT video_path, video_original_name FROM exercises WHERE id = ?',
            [$id]
        );

        if (!$exercise || !$exercise['video_path']) {
            throw $this->createNotFoundException('No video attached.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/' . $exercise['video_path'];
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Video file not found.');
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
        $mime = mime_content_type($filePath) ?: 'video/mp4';
        $response->headers->set('Content-Type', $mime);
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
            $exercise['video_original_name'] ?: basename($filePath)
        );
        // Allow range requests so the browser can seek in the video
        $response->headers->set('Accept-Ranges', 'bytes');
        return $response;
    }

    #[Route('/exercises/{id}/pdf', name: 'app_exercise_view_pdf', requirements: ['id' => '\d+'])]
    public function viewPdf(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $exercise = $this->conn()->fetchAssociative(
            'SELECT pdf_path, pdf_original_name FROM exercises WHERE id = ?',
            [$id]
        );

        if (!$exercise || !$exercise['pdf_path']) {
            throw $this->createNotFoundException('No PDF attached.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $vichPath = $projectDir . '/public/uploads/exercises/' . basename($exercise['pdf_path']);
        if (file_exists($vichPath)) {
            $filePath = $vichPath;
        } else {
            $filePath = $exercise['pdf_path'];
            if (!file_exists($filePath)) {
                $alt = $projectDir . '/var/exercises/' . basename($filePath);
                if (file_exists($alt)) {
                    $filePath = $alt;
                } else {
                    throw $this->createNotFoundException('File not found.');
                }
            }
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
            $exercise['pdf_original_name'] ?: basename($filePath)
        );
        return $response;
    }

    #[Route('/exercises/{id}/download', name: 'app_exercise_download')]
    public function download(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $exercise = $this->conn()->fetchAssociative(
            'SELECT pdf_path, pdf_original_name FROM exercises WHERE id = ?',
            [$id]
        );

        if (!$exercise || !$exercise['pdf_path']) {
            $this->addFlash('error', 'Exercise file not found.');
            return $this->redirectToRoute('app_exercises');
        }

        // Try VichUploader storage for files in the configured upload directory
        $projectDir = $this->getParameter('kernel.project_dir');
        $vichPath = $projectDir . '/public/uploads/exercises/' . basename($exercise['pdf_path']);
        if (file_exists($vichPath)) {
            $fileName = $exercise['pdf_original_name'] ?: basename($vichPath);
            return $this->file($vichPath, $fileName);
        }

        // Fall back to legacy paths
        $filePath = $exercise['pdf_path'];
        if (!file_exists($filePath)) {
            $projectDir = $this->getParameter('kernel.project_dir');
            $altPath = $projectDir . '/var/exercises/' . basename($filePath);
            if (file_exists($altPath)) {
                $filePath = $altPath;
            } else {
                $this->addFlash('error', 'File not found on server.');
                return $this->redirectToRoute('app_exercises');
            }
        }

        $fileName = $exercise['pdf_original_name'] ?: basename($filePath);

        return $this->file($filePath, $fileName);
    }
}
