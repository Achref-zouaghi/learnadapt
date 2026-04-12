<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
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

        // Check if locale is already set in session
        $sessionLocale = $session->get('_locale');
        $sessionTheme = $session->get('_theme');
        if ($sessionLocale && $sessionTheme) {
            $request->setLocale($sessionLocale);
            return;
        }

        // Otherwise try to get from user's DB preference
        $auth = $session->get(self::AUTH_SESSION_KEY);
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
