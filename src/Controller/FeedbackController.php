<?php

namespace App\Controller;

use App\Entity\AppFeedback;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FeedbackController extends AbstractController
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

    #[Route('/feedback', name: 'app_feedback')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $feedbacks = $this->connection->fetchAllAssociative(
            'SELECT af.*, u.full_name as author_name, u.avatar_base64 as author_avatar
             FROM app_feedback af
             JOIN users u ON af.user_id = u.id
             ORDER BY af.created_at DESC'
        );

        $stats = $this->connection->fetchAssociative(
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

        $myFeedbacks = $this->connection->fetchAllAssociative(
            'SELECT * FROM app_feedback WHERE user_id = ? ORDER BY created_at DESC',
            [$user->getId()]
        );

        return $this->render('feedback/index.html.twig', [
            'user' => $user,
            'feedbacks' => $feedbacks,
            'stats' => $stats,
            'myFeedbacks' => $myFeedbacks,
        ]);
    }

    #[Route('/feedback/submit', name: 'app_feedback_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $rating = (int) $request->request->get('rating', 0);
        $comment = trim($request->request->get('comment', ''));

        if ($rating < 1 || $rating > 5) {
            $this->addFlash('error', 'Please select a rating between 1 and 5.');
            return $this->redirectToRoute('app_feedback');
        }

        $feedback = new AppFeedback();
        $feedback->setUser($user);
        $feedback->setRating($rating);
        $feedback->setComment($comment !== '' ? $comment : null);
        $feedback->setCreated_at(new \DateTime());

        $this->entityManager->persist($feedback);

        // Create notification
        $this->connection->executeStatement(
            'INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())',
            [
                $user->getId(),
                'FEEDBACK_SENT',
                '⭐ Feedback sent!',
                $rating >= 4
                    ? "Thank you for your positive feedback ({$rating}⭐)!"
                    : "Thank you for your feedback ({$rating}⭐). We'll improve!",
            ]
        );

        $this->entityManager->flush();

        $this->addFlash('success', 'Thank you for your feedback!');
        return $this->redirectToRoute('app_feedback');
    }
}
