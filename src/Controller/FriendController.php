<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FriendController extends AbstractController
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

    /**
     * Get pending friend requests for current user (JSON).
     */
    #[Route('/friends/requests', name: 'app_friend_requests', methods: ['GET'])]
    public function requests(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT fr.id, fr.sender_id, fr.created_at, u.full_name, u.role, u.avatar_base64
             FROM friend_requests fr
             JOIN users u ON u.id = fr.sender_id
             WHERE fr.receiver_id = ? AND fr.status = ?
             ORDER BY fr.created_at DESC',
            [$user->getId(), 'pending']
        );

        return new JsonResponse(['requests' => $rows]);
    }

    /**
     * Get count of pending friend requests (for badge).
     */
    #[Route('/friends/requests/count', name: 'app_friend_requests_count', methods: ['GET'])]
    public function requestsCount(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['count' => 0]);
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM friend_requests WHERE receiver_id = ? AND status = ?',
            [$user->getId(), 'pending']
        );

        return new JsonResponse(['count' => $count]);
    }

    /**
     * Send a friend request.
     */
    #[Route('/friends/send/{receiverId}', name: 'app_friend_send', methods: ['POST'])]
    public function send(Request $request, int $receiverId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if ($user->getId() === $receiverId) {
            return new JsonResponse(['error' => 'Cannot send request to yourself'], 400);
        }

        // Check if a request already exists in either direction
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM friend_requests
             WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)',
            [$user->getId(), $receiverId, $receiverId, $user->getId()]
        );

        if ((int) $existing > 0) {
            return new JsonResponse(['error' => 'Request already exists', 'status' => 'exists']);
        }

        $this->connection->insert('friend_requests', [
            'sender_id' => $user->getId(),
            'receiver_id' => $receiverId,
            'status' => 'pending',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        // Create notification for receiver
        $this->connection->insert('notifications', [
            'user_id' => $receiverId,
            'type' => 'FRIEND_REQUEST',
            'title' => 'Friend Request',
            'message' => $user->getFullName() . ' sent you a friend request.',
            'is_read' => 0,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return new JsonResponse(['success' => true, 'status' => 'sent']);
    }

    /**
     * Accept a friend request.
     */
    #[Route('/friends/accept/{requestId}', name: 'app_friend_accept', methods: ['POST'])]
    public function accept(Request $request, int $requestId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $fr = $this->connection->fetchAssociative(
            'SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = ?',
            [$requestId, $user->getId(), 'pending']
        );

        if (!$fr) {
            return new JsonResponse(['error' => 'Request not found'], 404);
        }

        $this->connection->update('friend_requests', [
            'status' => 'accepted',
            'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $requestId]);

        // Notify sender
        $this->connection->insert('notifications', [
            'user_id' => $fr['sender_id'],
            'type' => 'FRIEND_ACCEPTED',
            'title' => 'Friend Request Accepted',
            'message' => $user->getFullName() . ' accepted your friend request.',
            'is_read' => 0,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return new JsonResponse(['success' => true, 'status' => 'accepted']);
    }

    /**
     * Decline a friend request.
     */
    #[Route('/friends/decline/{requestId}', name: 'app_friend_decline', methods: ['POST'])]
    public function decline(Request $request, int $requestId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $fr = $this->connection->fetchAssociative(
            'SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = ?',
            [$requestId, $user->getId(), 'pending']
        );

        if (!$fr) {
            return new JsonResponse(['error' => 'Request not found'], 404);
        }

        $this->connection->update('friend_requests', [
            'status' => 'declined',
            'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $requestId]);

        return new JsonResponse(['success' => true, 'status' => 'declined']);
    }

    /**
     * Search users by name (for search bar).
     */
    #[Route('/friends/search', name: 'app_friend_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $q = trim($request->query->get('q', ''));
        if (strlen($q) < 2) {
            return new JsonResponse(['results' => []]);
        }

        $searchTerm = '%' . $q . '%';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.full_name, u.role, u.avatar_base64,
                    (SELECT fr.status FROM friend_requests fr
                     WHERE (fr.sender_id = ? AND fr.receiver_id = u.id)
                        OR (fr.sender_id = u.id AND fr.receiver_id = ?)
                     LIMIT 1) AS friendship_status
             FROM users u
             WHERE u.id != ? AND u.full_name LIKE ?
             ORDER BY u.full_name ASC
             LIMIT 20',
            [$user->getId(), $user->getId(), $user->getId(), $searchTerm]
        );

        return new JsonResponse(['results' => $rows]);
    }

    /**
     * List accepted friends.
     */
    #[Route('/friends', name: 'app_friends', methods: ['GET'])]
    public function friends(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.full_name, u.role, u.avatar_base64
             FROM friend_requests fr
             JOIN users u ON (u.id = CASE WHEN fr.sender_id = ? THEN fr.receiver_id ELSE fr.sender_id END)
             WHERE (fr.sender_id = ? OR fr.receiver_id = ?) AND fr.status = ?
             ORDER BY u.full_name ASC',
            [$user->getId(), $user->getId(), $user->getId(), 'accepted']
        );

        return new JsonResponse(['friends' => $rows]);
    }
}
