<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Update last_activity for online status (throttle to once per 30 seconds)
        $auth = $session->get(self::AUTH_SESSION_KEY);
        if (is_array($auth) && isset($auth['id'])) {
            try {
                $lastPing = $session->get('_last_activity_ping', 0);
                if (time() - $lastPing > 30) {
                    $this->connection->executeStatement(
                        'UPDATE users SET last_activity = NOW() WHERE id = ?',
                        [(int) $auth['id']]
                    );
                    $session->set('_last_activity_ping', time());
                }
            } catch (\Throwable $e) {
                // Never crash the page for activity tracking
            }
        }

        // Check if locale is already set in session
        $sessionLocale = $session->get('_locale');
        $sessionTheme = $session->get('_theme');
        if ($sessionLocale && $sessionTheme) {
            $request->setLocale($sessionLocale);
            return;
        }

        // Otherwise try to get from user's DB preference
        if (is_array($auth) && isset($auth['id'])) {
            $user = $this->userRepository->find((int) $auth['id']);
            if ($user) {
                if (!$sessionLocale) {
                    $locale = $user->getLocale();
                    $session->set('_locale', $locale);
                    $request->setLocale($locale);
                }
                if (!$sessionTheme) {
                    $session->set('_theme', $user->getTheme());
                }
            }
        }
    }
}
