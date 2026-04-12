<?php

namespace App\Twig;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class NavbarExtension extends AbstractExtension implements GlobalsInterface
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
        private readonly Connection $connection,
    ) {
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $session = $request->getSession();
        $auth = $session->get(self::AUTH_SESSION_KEY);

        if (!is_array($auth) || !isset($auth['id'])) {
            return ['navUser' => null, 'unreadCount' => 0, 'unreadNotifications' => []];
        }

        $userId = (int) $auth['id'];
        $user = $this->userRepository->find($userId);

        if ($user === null) {
            return ['navUser' => null, 'unreadCount' => 0, 'unreadNotifications' => []];
        }

        $unreadNotifications = $this->connection->fetchAllAssociative(
            'SELECT id, type, title, message, created_at, related_topic_id FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10',
            [$userId]
        );

        return [
            'navUser' => $user,
            'unreadCount' => count($unreadNotifications),
            'unreadNotifications' => $unreadNotifications,
            'userTheme' => $session->get('_theme', $user->getTheme()),
        ];
    }
}
