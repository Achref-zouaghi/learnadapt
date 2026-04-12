<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CourseController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
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

        $sql = 'SELECT c.*, m.name as module_name, u.full_name as teacher_name
                FROM courses c
                LEFT JOIN modules m ON c.module_id = m.id
                LEFT JOIN users u ON c.teacher_user_id = u.id
                WHERE 1=1';
        $params = [];

        if ($level && in_array($level, ['EASY', 'MEDIUM', 'HARD'], true)) {
            $sql .= ' AND c.level = ?';
            $params[] = $level;
        }
        if ($moduleId !== '') {
            $sql .= ' AND c.module_id = ?';
            $params[] = (int) $moduleId;
        }
        if ($search) {
            $sql .= ' AND (c.title LIKE ? OR c.description LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY c.created_at DESC';

        $courses = $this->connection->fetchAllAssociative($sql, $params);

        $counts = $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN level = \'EASY\' THEN 1 ELSE 0 END) as easy,
                SUM(CASE WHEN level = \'MEDIUM\' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN level = \'HARD\' THEN 1 ELSE 0 END) as hard
             FROM courses'
        );

        $modules = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.name, COUNT(c.id) as course_count
             FROM modules m
             INNER JOIN courses c ON c.module_id = m.id
             GROUP BY m.id, m.name
             ORDER BY m.name'
        );

        return $this->render('courses/index.html.twig', [
            'courses' => $courses,
            'counts' => $counts,
            'modules' => $modules,
            'currentLevel' => $level,
            'currentModule' => $moduleId,
            'searchQuery' => $search,
        ]);
    }

    #[Route('/courses/{id}', name: 'app_course_show', requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $course = $this->connection->fetchAssociative(
            'SELECT c.*, m.name as module_name, u.full_name as teacher_name
             FROM courses c
             LEFT JOIN modules m ON c.module_id = m.id
             LEFT JOIN users u ON c.teacher_user_id = u.id
             WHERE c.id = ?',
            [$id]
        );

        if (!$course) {
            throw $this->createNotFoundException('Course not found.');
        }

        $exercises = $this->connection->fetchAllAssociative(
            'SELECT e.* FROM exercice e
             INNER JOIN course_exercises ce ON ce.exercise_id = e.id
             WHERE ce.course_id = ?',
            [$id]
        );

        return $this->render('courses/show.html.twig', [
            'course' => $course,
            'exercises' => $exercises,
        ]);
    }
}
