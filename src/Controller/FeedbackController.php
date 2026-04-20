<?php

namespace App\Controller;

use App\Entity\AppFeedback;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FeedbackController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
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

        $feedbacks = $this->conn()->fetchAllAssociative(
            'SELECT af.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                    (SELECT COUNT(*) FROM feedback_reactions fr WHERE fr.feedback_id = af.id AND fr.type = ?) as like_count,
                    (SELECT COUNT(*) FROM feedback_reactions fr WHERE fr.feedback_id = af.id AND fr.type = ?) as dislike_count,
                    (SELECT fr.type FROM feedback_reactions fr WHERE fr.feedback_id = af.id AND fr.user_id = ?) as my_reaction
             FROM app_feedback af
             JOIN users u ON af.user_id = u.id
             ORDER BY af.created_at DESC',
            ['like', 'dislike', $user->getId()]
        );

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

        $myFeedbacks = $this->conn()->fetchAllAssociative(
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
        $this->conn()->executeStatement(
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

    #[Route('/feedback/{id}/edit', name: 'app_feedback_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $feedback = $this->entityManager->getRepository(AppFeedback::class)->find($id);
        if (!$feedback || $feedback->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only edit your own feedback.');
            return $this->redirectToRoute('app_feedback');
        }

        $rating = (int) $request->request->get('rating', 0);
        $comment = trim($request->request->get('comment', ''));

        if ($rating < 1 || $rating > 5) {
            $this->addFlash('error', 'Please select a rating between 1 and 5.');
            return $this->redirectToRoute('app_feedback');
        }

        $feedback->setRating($rating);
        $feedback->setComment($comment !== '' ? $comment : null);
        $this->entityManager->flush();

        $this->addFlash('success', 'Feedback updated!');
        return $this->redirectToRoute('app_feedback');
    }

    #[Route('/feedback/{id}/delete', name: 'app_feedback_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $feedback = $this->entityManager->getRepository(AppFeedback::class)->find($id);
        if (!$feedback || $feedback->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only delete your own feedback.');
            return $this->redirectToRoute('app_feedback');
        }

        $this->entityManager->remove($feedback);
        $this->entityManager->flush();

        $this->addFlash('success', 'Feedback deleted!');
        return $this->redirectToRoute('app_feedback');
    }

    #[Route('/feedback/{id}/react', name: 'app_feedback_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function react(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $type = $request->request->get('type', '');
        if (!in_array($type, ['like', 'dislike'], true)) {
            $this->addFlash('error', 'Invalid reaction.');
            return $this->redirectToRoute('app_feedback');
        }

        $feedback = $this->conn()->fetchAssociative(
            'SELECT af.*, u.full_name as author_name FROM app_feedback af JOIN users u ON af.user_id = u.id WHERE af.id = ?',
            [$id]
        );
        if (!$feedback) {
            $this->addFlash('error', 'Feedback not found.');
            return $this->redirectToRoute('app_feedback');
        }

        // Check existing reaction
        $existing = $this->conn()->fetchAssociative(
            'SELECT * FROM feedback_reactions WHERE feedback_id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        if ($existing) {
            if ($existing['type'] === $type) {
                // Toggle off — remove reaction
                $this->conn()->executeStatement(
                    'DELETE FROM feedback_reactions WHERE id = ?',
                    [$existing['id']]
                );
            } else {
                // Switch reaction type
                $this->conn()->executeStatement(
                    'UPDATE feedback_reactions SET type = ?, created_at = NOW() WHERE id = ?',
                    [$type, $existing['id']]
                );
                // Notify feedback author
                if ((int) $feedback['user_id'] !== $user->getId()) {
                    $emoji = $type === 'like' ? '👍' : '👎';
                    $this->conn()->executeStatement(
                        'INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                         VALUES (?, ?, ?, ?, 0, NOW())',
                        [
                            $feedback['user_id'],
                            'FEEDBACK_REACTION',
                            "{$emoji} {$user->getFullName()} " . ($type === 'like' ? 'liked' : 'disliked') . " your review",
                            "{$user->getFullName()} {$type}d your feedback: \"" . mb_substr($feedback['comment'] ?? 'No comment', 0, 60) . "\"",
                        ]
                    );
                }
            }
        } else {
            // New reaction
            $this->conn()->executeStatement(
                'INSERT INTO feedback_reactions (feedback_id, user_id, type, created_at) VALUES (?, ?, ?, NOW())',
                [$id, $user->getId(), $type]
            );
            // Notify feedback author (don't notify yourself)
            if ((int) $feedback['user_id'] !== $user->getId()) {
                $emoji = $type === 'like' ? '👍' : '👎';
                $this->conn()->executeStatement(
                    'INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                     VALUES (?, ?, ?, ?, 0, NOW())',
                    [
                        $feedback['user_id'],
                        'FEEDBACK_REACTION',
                        "{$emoji} {$user->getFullName()} " . ($type === 'like' ? 'liked' : 'disliked') . " your review",
                        "{$user->getFullName()} {$type}d your feedback: \"" . mb_substr($feedback['comment'] ?? 'No comment', 0, 60) . "\"",
                    ]
                );
            }
        }

        // Return JSON for AJAX requests
        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            $likeCount = (int) $this->conn()->fetchOne(
                'SELECT COUNT(*) FROM feedback_reactions WHERE feedback_id = ? AND type = ?',
                [$id, 'like']
            );
            $dislikeCount = (int) $this->conn()->fetchOne(
                'SELECT COUNT(*) FROM feedback_reactions WHERE feedback_id = ? AND type = ?',
                [$id, 'dislike']
            );
            $myReaction = $this->conn()->fetchOne(
                'SELECT type FROM feedback_reactions WHERE feedback_id = ? AND user_id = ?',
                [$id, $user->getId()]
            );

            return new JsonResponse([
                'like_count' => $likeCount,
                'dislike_count' => $dislikeCount,
                'my_reaction' => $myReaction ?: null,
            ]);
        }

        return $this->redirectToRoute('app_feedback');
    }
}
