<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class QuizController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function getAuthUser(Request $request): ?array
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        return $auth;
    }

    #[Route('/quizzes', name: 'app_quizzes')]
    public function index(Request $request): Response
    {
        $auth = $this->getAuthUser($request);
        if (!$auth) {
            return $this->redirectToRoute('app_login');
        }

        $allQuizzes = $this->conn()->fetchAllAssociative(
            'SELECT q.*,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) as question_count,
                    (SELECT SUM(qq2.points) FROM quiz_questions qq2 WHERE qq2.quiz_id = q.id) as total_points
             FROM diagnostic_quizzes q
             WHERE q.is_active = 1
             ORDER BY q.created_at DESC'
        );

        // Paginate using KnpPaginatorBundle (2 per page for demo; change to 6 in production)
        $quizzes = $this->paginator->paginate(
            $allQuizzes,
            $request->query->getInt('page', 1),
            2
        );

        // Get user's attempts for each quiz
        $attempts = $this->conn()->fetchAllAssociative(
            'SELECT qa.quiz_id,
                    COUNT(*) as attempt_count,
                    MAX(qa.score_percent) as best_score,
                    MAX(qa.finished_at) as last_attempt
             FROM quiz_attempts qa
             WHERE qa.student_user_id = ?
             GROUP BY qa.quiz_id',
            [(int) $auth['id']]
        );

        $attemptMap = [];
        foreach ($attempts as $a) {
            $attemptMap[$a['quiz_id']] = $a;
        }

        // ChartjsBundle — doughnut chart showing quiz completion
        $completedCount = count(array_filter($attempts, fn($a) => (float)$a['best_score'] > 0));
        $totalCount     = count($allQuizzes);
        $scoreChart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $scoreChart->setData([
            'labels'   => ['Completed', 'Remaining'],
            'datasets' => [[
                'data'            => [$completedCount, max(0, $totalCount - $completedCount)],
                'backgroundColor' => ['rgba(167,139,250,0.85)', 'rgba(255,255,255,0.07)'],
                'borderColor'     => ['#7c3aed', 'rgba(255,255,255,0.0)'],
                'borderWidth'     => [2, 0],
                'hoverOffset'     => 4,
            ]],
        ]);
        $scoreChart->setOptions([
            'cutout'  => '72%',
            'plugins' => ['legend' => ['display' => false], 'tooltip' => ['enabled' => false]],
            'animation' => ['animateRotate' => true, 'duration' => 900],
        ]);

        return $this->render('quiz/index.html.twig', [
            'quizzes'    => $quizzes,
            'attemptMap' => $attemptMap,
            'scoreChart' => $scoreChart,
        ]);
    }

    #[Route('/quizzes/{id}/start', name: 'app_quiz_start', methods: ['POST'])]
    public function start(int $id, Request $request): Response
    {
        $auth = $this->getAuthUser($request);
        if (!$auth) {
            return $this->redirectToRoute('app_login');
        }

        $quiz = $this->conn()->fetchAssociative(
            'SELECT * FROM diagnostic_quizzes WHERE id = ? AND is_active = 1',
            [$id]
        );

        if (!$quiz) {
            return $this->redirectToRoute('app_quizzes');
        }

        // Check if there's an unfinished attempt
        $existing = $this->conn()->fetchAssociative(
            'SELECT id FROM quiz_attempts WHERE quiz_id = ? AND student_user_id = ? AND finished_at IS NULL',
            [$id, (int) $auth['id']]
        );

        if ($existing) {
            return $this->redirectToRoute('app_quiz_take', ['id' => $existing['id']]);
        }

        // Count questions and total points
        $stats = $this->conn()->fetchAssociative(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(points), 0) as total FROM quiz_questions WHERE quiz_id = ?',
            [$id]
        );

        // Create new attempt
        $this->conn()->executeStatement(
            'INSERT INTO quiz_attempts (quiz_id, student_user_id, started_at, total_points) VALUES (?, ?, NOW(), ?)',
            [$id, (int) $auth['id'], (int) $stats['total']]
        );

        $attemptId = (int) $this->conn()->lastInsertId();

        return $this->redirectToRoute('app_quiz_take', ['id' => $attemptId]);
    }

    #[Route('/quizzes/attempt/{id}', name: 'app_quiz_take')]
    public function take(int $id, Request $request): Response
    {
        $auth = $this->getAuthUser($request);
        if (!$auth) {
            return $this->redirectToRoute('app_login');
        }

        $attempt = $this->conn()->fetchAssociative(
            'SELECT a.*, q.title as quiz_title, q.description as quiz_description, q.time_limit_minutes
             FROM quiz_attempts a
             JOIN diagnostic_quizzes q ON q.id = a.quiz_id
             WHERE a.id = ? AND a.student_user_id = ?',
            [$id, (int) $auth['id']]
        );

        if (!$attempt) {
            return $this->redirectToRoute('app_quizzes');
        }

        // If already finished, show results
        if ($attempt['finished_at']) {
            return $this->redirectToRoute('app_quiz_results', ['id' => $id]);
        }

        // Check time limit
        $timeExpired = false;
        $remainingSeconds = null;
        if ($attempt['time_limit_minutes']) {
            $startedAt = new \DateTime($attempt['started_at']);
            $deadline = clone $startedAt;
            $deadline->modify('+' . (int) $attempt['time_limit_minutes'] . ' minutes');
            $now = new \DateTime();

            if ($now >= $deadline) {
                $timeExpired = true;
            } else {
                $remainingSeconds = $deadline->getTimestamp() - $now->getTimestamp();
            }
        }

        if ($timeExpired) {
            return $this->autoSubmit($id, $auth);
        }

        // Get questions
        $questions = $this->conn()->fetchAllAssociative(
            'SELECT id, question_type, prompt, option_a, option_b, option_c, option_d, points, difficulty
             FROM quiz_questions
             WHERE quiz_id = ?
             ORDER BY id ASC',
            [(int) $attempt['quiz_id']]
        );

        // Get already answered questions
        $answered = $this->conn()->fetchAllAssociative(
            'SELECT question_id, chosen_option, chosen_bool, typed_answer FROM quiz_answers WHERE attempt_id = ?',
            [$id]
        );
        $answeredMap = [];
        foreach ($answered as $ans) {
            $answeredMap[$ans['question_id']] = $ans;
        }

        return $this->render('quiz/take.html.twig', [
            'attempt' => $attempt,
            'questions' => $questions,
            'answeredMap' => $answeredMap,
            'remainingSeconds' => $remainingSeconds,
        ]);
    }

    private function autoSubmit(int $attemptId, array $auth): Response
    {
        // Get attempt
        $attempt = $this->conn()->fetchAssociative(
            'SELECT * FROM quiz_attempts WHERE id = ? AND student_user_id = ?',
            [$attemptId, (int) $auth['id']]
        );

        if (!$attempt || $attempt['finished_at']) {
            return $this->redirectToRoute('app_quiz_results', ['id' => $attemptId]);
        }

        // Calculate score from existing answers
        $score = $this->conn()->fetchAssociative(
            'SELECT COALESCE(SUM(earned_points), 0) as earned FROM quiz_answers WHERE attempt_id = ?',
            [$attemptId]
        );

        $earned = (int) $score['earned'];
        $total = (int) $attempt['total_points'];
        $percent = $total > 0 ? round(($earned / $total) * 100, 2) : 0;

        $level = 'BEGINNER';
        if ($percent >= 80) {
            $level = 'ADVANCED';
        } elseif ($percent >= 50) {
            $level = 'INTERMEDIATE';
        }

        $this->conn()->executeStatement(
            'UPDATE quiz_attempts SET finished_at = NOW(), earned_points = ?, score_percent = ?, level_result = ? WHERE id = ?',
            [$earned, $percent, $level, $attemptId]
        );

        return $this->redirectToRoute('app_quiz_results', ['id' => $attemptId]);
    }

    #[Route('/quizzes/attempt/{id}/submit', name: 'app_quiz_submit', methods: ['POST'])]
    public function submit(int $id, Request $request): Response
    {
        $auth = $this->getAuthUser($request);
        if (!$auth) {
            return $this->redirectToRoute('app_login');
        }

        $attempt = $this->conn()->fetchAssociative(
            'SELECT a.*, q.time_limit_minutes FROM quiz_attempts a
             JOIN diagnostic_quizzes q ON q.id = a.quiz_id
             WHERE a.id = ? AND a.student_user_id = ? AND a.finished_at IS NULL',
            [$id, (int) $auth['id']]
        );

        if (!$attempt) {
            return $this->redirectToRoute('app_quizzes');
        }

        // Get all questions for this quiz
        $questions = $this->conn()->fetchAllAssociative(
            'SELECT * FROM quiz_questions WHERE quiz_id = ?',
            [(int) $attempt['quiz_id']]
        );

        $totalEarned = 0;

        foreach ($questions as $q) {
            $qId = $q['id'];
            $answerKey = 'q_' . $qId;

            // Check if already answered
            $existing = $this->conn()->fetchAssociative(
                'SELECT id FROM quiz_answers WHERE attempt_id = ? AND question_id = ?',
                [$id, $qId]
            );

            $chosenOption = null;
            $chosenBool = null;
            $typedAnswer = null;
            $isCorrect = false;
            $earnedPoints = 0;

            $userAnswer = $request->request->get($answerKey, '');

            switch ($q['question_type']) {
                case 'MCQ':
                    $chosenOption = in_array(strtoupper($userAnswer), ['A', 'B', 'C', 'D'], true) ? strtoupper($userAnswer) : null;
                    if ($chosenOption && $chosenOption === $q['correct_option']) {
                        $isCorrect = true;
                        $earnedPoints = (int) $q['points'];
                    }
                    break;

                case 'TRUE_FALSE':
                    if ($userAnswer !== '') {
                        $chosenBool = (int) $userAnswer;
                        if ($chosenBool === (int) $q['correct_bool']) {
                            $isCorrect = true;
                            $earnedPoints = (int) $q['points'];
                        }
                    }
                    break;

                case 'SHORT_TEXT':
                    $typedAnswer = trim($userAnswer);
                    if ($typedAnswer !== '' && $q['correct_text'] !== null) {
                        if (mb_strtolower($typedAnswer) === mb_strtolower(trim($q['correct_text']))) {
                            $isCorrect = true;
                            $earnedPoints = (int) $q['points'];
                        }
                    }
                    break;
            }

            if ($existing) {
                $this->conn()->executeStatement(
                    'UPDATE quiz_answers SET chosen_option = ?, chosen_bool = ?, typed_answer = ?, is_correct = ?, earned_points = ? WHERE id = ?',
                    [$chosenOption, $chosenBool, $typedAnswer, $isCorrect ? 1 : 0, $earnedPoints, $existing['id']]
                );
            } else {
                $this->conn()->executeStatement(
                    'INSERT INTO quiz_answers (attempt_id, question_id, chosen_option, chosen_bool, typed_answer, is_correct, earned_points) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $qId, $chosenOption, $chosenBool, $typedAnswer, $isCorrect ? 1 : 0, $earnedPoints]
                );
            }

            $totalEarned += $earnedPoints;
        }

        $totalPoints = (int) $attempt['total_points'];
        $percent = $totalPoints > 0 ? round(($totalEarned / $totalPoints) * 100, 2) : 0;

        $level = 'BEGINNER';
        if ($percent >= 80) {
            $level = 'ADVANCED';
        } elseif ($percent >= 50) {
            $level = 'INTERMEDIATE';
        }

        $this->conn()->executeStatement(
            'UPDATE quiz_attempts SET finished_at = NOW(), earned_points = ?, score_percent = ?, level_result = ? WHERE id = ?',
            [$totalEarned, $percent, $level, $id]
        );

        return $this->redirectToRoute('app_quiz_results', ['id' => $id]);
    }

    #[Route('/quizzes/attempt/{id}/results', name: 'app_quiz_results')]
    public function results(int $id, Request $request): Response
    {
        $auth = $this->getAuthUser($request);
        if (!$auth) {
            return $this->redirectToRoute('app_login');
        }

        $attempt = $this->conn()->fetchAssociative(
            'SELECT a.*, q.title as quiz_title, q.description as quiz_description, q.time_limit_minutes
             FROM quiz_attempts a
             JOIN diagnostic_quizzes q ON q.id = a.quiz_id
             WHERE a.id = ? AND a.student_user_id = ?',
            [$id, (int) $auth['id']]
        );

        if (!$attempt || !$attempt['finished_at']) {
            return $this->redirectToRoute('app_quizzes');
        }

        // Get all questions with user answers
        $questions = $this->conn()->fetchAllAssociative(
            'SELECT qq.*, qa.chosen_option, qa.chosen_bool, qa.typed_answer, qa.is_correct, qa.earned_points as user_earned
             FROM quiz_questions qq
             LEFT JOIN quiz_answers qa ON qa.question_id = qq.id AND qa.attempt_id = ?
             WHERE qq.quiz_id = ?
             ORDER BY qq.id ASC',
            [$id, (int) $attempt['quiz_id']]
        );

        return $this->render('quiz/results.html.twig', [
            'attempt' => $attempt,
            'questions' => $questions,
        ]);
    }
}
