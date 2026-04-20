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

        $filePath = $exercise['pdf_path'];
        // Support both absolute and relative paths
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
