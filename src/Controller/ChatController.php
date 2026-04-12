<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ChatController extends AbstractController
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

    /**
     * Get contacts: teachers/experts by default, plus recent conversations.
     */
    #[Route('/chat/contacts', name: 'app_chat_contacts', methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $myId = $user->getId();

        // Teachers & experts
        $advisors = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.full_name, LOWER(u.role) AS role, u.avatar_base64, u.last_activity
             FROM users u
             WHERE LOWER(u.role) IN ('teacher', 'expert')
               AND u.id != ?
               AND u.is_active = 1
             ORDER BY u.role ASC, u.full_name ASC",
            [$myId]
        );

        // Admins
        $admins = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.full_name, LOWER(u.role) AS role, u.avatar_base64, u.last_activity
             FROM users u
             WHERE LOWER(u.role) = 'admin'
               AND u.id != ?
               AND u.is_active = 1
             ORDER BY u.full_name ASC",
            [$myId]
        );

        // All students
        $students = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.full_name, LOWER(u.role) AS role, u.avatar_base64, u.last_activity
             FROM users u
             WHERE LOWER(u.role) = 'student'
               AND u.id != ?
               AND u.is_active = 1
             ORDER BY u.full_name ASC",
            [$myId]
        );

        // Accepted friends
        $friends = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.full_name, LOWER(u.role) AS role, u.avatar_base64, u.last_activity
             FROM users u
             INNER JOIN friend_requests fr
               ON (fr.sender_id = u.id AND fr.receiver_id = ?)
               OR (fr.receiver_id = u.id AND fr.sender_id = ?)
             WHERE fr.status = 'accepted'
               AND u.id != ?
               AND u.is_active = 1
             ORDER BY u.full_name ASC",
            [$myId, $myId, $myId]
        );

        // Total unread
        $totalUnread = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messages_prives WHERE receiver_id = ? AND is_read = 0",
            [$myId]
        );

        return new JsonResponse([
            'advisors' => $advisors,
            'admins' => $admins,
            'students' => $students,
            'friends' => $friends,
            'totalUnread' => $totalUnread,
        ]);
    }

    /**
     * Get conversation messages with a specific user.
     */
    #[Route('/chat/messages/{contactId}', name: 'app_chat_messages', methods: ['GET'], requirements: ['contactId' => '\d+'])]
    public function messages(Request $request, int $contactId): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $myId = $user->getId();

        // Mark messages from this contact as read
        $this->connection->executeStatement(
            "UPDATE messages_prives SET is_read = 1
             WHERE sender_id = ? AND receiver_id = ? AND is_read = 0",
            [$contactId, $myId]
        );

        $messages = $this->connection->fetchAllAssociative(
            "SELECT id, sender_id, receiver_id, content, is_read, created_at
             FROM messages_prives
             WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
             ORDER BY created_at ASC
             LIMIT 200",
            [$myId, $contactId, $contactId, $myId]
        );

        // Contact info
        $contact = $this->connection->fetchAssociative(
            "SELECT id, full_name, role, avatar_base64 FROM users WHERE id = ?",
            [$contactId]
        );

        return new JsonResponse([
            'messages' => $messages,
            'contact' => $contact,
        ]);
    }

    /**
     * Send a private message.
     */
    #[Route('/chat/send', name: 'app_chat_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $receiverId = isset($data['receiver_id']) ? (int) $data['receiver_id'] : 0;
        $content = isset($data['content']) ? trim($data['content']) : '';

        if ($receiverId <= 0 || $content === '') {
            return new JsonResponse(['error' => 'Invalid data'], 400);
        }

        // Prevent self-messaging
        if ($receiverId === $user->getId()) {
            return new JsonResponse(['error' => 'Cannot message yourself'], 400);
        }

        // Verify receiver exists
        $receiver = $this->connection->fetchAssociative("SELECT id, role FROM users WHERE id = ?", [$receiverId]);
        if (!$receiver) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->connection->insert('messages_prives', [
            'sender_id' => $user->getId(),
            'receiver_id' => $receiverId,
            'content' => $content,
            'is_read' => 0,
            'created_at' => $now,
        ]);

        $id = (int) $this->connection->lastInsertId();

        // Create a notification for the receiver
        $snippet = mb_substr($content, 0, 80);
        $this->connection->executeStatement(
            'INSERT INTO notifications (user_id, type, title, message, is_read, related_topic_id, created_at)
             VALUES (?, ?, ?, ?, 0, ?, NOW())',
            [
                $receiverId,
                'PRIVATE_MESSAGE',
                "💬 " . $user->getFullName() . " sent you a message",
                $snippet,
                $user->getId(),
            ]
        );

        return new JsonResponse([
            'success' => true,
            'message' => [
                'id' => $id,
                'sender_id' => $user->getId(),
                'receiver_id' => $receiverId,
                'content' => $content,
                'is_read' => 0,
                'created_at' => $now,
            ],
        ]);
    }

    /**
     * Get unread message count (for badge).
     */
    #[Route('/chat/unread-count', name: 'app_chat_unread_count', methods: ['GET'])]
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            return new JsonResponse(['count' => 0]);
        }

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messages_prives WHERE receiver_id = ? AND is_read = 0",
            [$user->getId()]
        );

        return new JsonResponse(['count' => $count]);
    }
}
