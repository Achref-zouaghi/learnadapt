<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ServerGateSubscriber implements EventSubscriberInterface
{
    private const GATE_SESSION_KEY = 'server.gate_passed';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 30]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $serverPassword = $_ENV['SERVER_GATE_PASSWORD'] ?? '';

        if ($serverPassword === '') {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/api/')) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if ($session->get(self::GATE_SESSION_KEY) === true) {
            return;
        }

        if ($request->isMethod('POST') && $request->request->has('gate_password')) {
            $entered = (string) $request->request->get('gate_password', '');
            if (hash_equals($serverPassword, $entered)) {
                $session->set(self::GATE_SESSION_KEY, true);
                $event->setResponse(new RedirectResponse($path));
                return;
            }
            $event->setResponse($this->buildGatePage($path, true));
            return;
        }

        $event->setResponse($this->buildGatePage($path, false));
    }

    private function buildGatePage(string $path, bool $error): Response
    {
        $safePath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
        $errorHtml = $error ? '<p class="error">Wrong password. Try again.</p>' : '';
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>LearnAdapt - Server Access</title><style>* { box-sizing: border-box; margin: 0; padding: 0; } body { background: #0f0f1a; color: #e2e8f0; font-family: Inter, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; } .card { background: #1a1a2e; border: 1px solid rgba(168,85,247,0.2); border-radius: 1rem; padding: 2.5rem 2rem; width: 100%; max-width: 380px; text-align: center; box-shadow: 0 0 40px rgba(168,85,247,0.1); } .logo { font-size: 1.5rem; font-weight: 700; color: #a855f7; margin-bottom: 0.5rem; } .subtitle { color: #94a3b8; font-size: 0.875rem; margin-bottom: 2rem; } input[type=password] { width: 100%; padding: 0.75rem 1rem; background: #0f0f1a; border: 1px solid rgba(168,85,247,0.3); border-radius: 0.5rem; color: #e2e8f0; font-size: 1rem; margin-bottom: 1rem; outline: none; } input[type=password]:focus { border-color: #a855f7; } button { width: 100%; padding: 0.75rem; background: #a855f7; color: white; border: none; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; } button:hover { background: #9333ea; } .error { color: #f87171; font-size: 0.875rem; margin-bottom: 1rem; }</style></head><body><div class="card"><div class="logo">LearnAdapt</div><div class="subtitle">Enter the server password to continue</div>' . $errorHtml . '<form method="POST" action="' . $safePath . '"><input type="password" name="gate_password" placeholder="Server password" autofocus required><button type="submit">Enter</button></form></div></body></html>';
        return new Response($html, 401);
    }
}