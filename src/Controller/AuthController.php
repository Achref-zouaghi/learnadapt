<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuthController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const VERIFICATION_SESSION_KEY = 'auth.pending_verification';
    private const GOOGLE_STATE_SESSION_KEY = 'auth.google_state';
    private const GITHUB_STATE_SESSION_KEY = 'auth.github_state';
    private const VERIFICATION_TTL = 600;
    private const MAX_CODE_ATTEMPTS = 5;
    private const PUBLIC_ROLES = ['STUDENT', 'TEACHER', 'PARENT', 'EXPERT'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->getAuthenticatedUser($request) instanceof User) {
            return $this->redirectToRoute('app_home');
        }

        $loginData = [
            'email' => trim((string) $request->request->get('email', '')),
        ];

        if ($request->isMethod('POST')) {
            $email = $this->normalizeEmail($loginData['email']);
            $password = (string) $request->request->get('password', '');

            if ($email === '' || $password === '') {
                $this->addFlash('error', 'Enter your email and password.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Enter a valid email address.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);

            if (!$user instanceof User || !$this->verifyUserPassword($user, $password)) {
                $this->addFlash('error', 'Invalid email or password.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            if (!$user->isActive()) {
                $this->addFlash('error', 'This account is inactive.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            if (!$this->canStartVerification()) {
                $this->addFlash('error', 'Configure MAILER_DSN to send real verification emails.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            try {
                $this->beginVerification(
                    $request->getSession(),
                    'login',
                    $user->getEmail() ?? $email,
                    ['user_id' => $user->getId()],
                    'Your LearnAdapt login code',
                    'Use this verification code to complete your LearnAdapt sign-in.'
                );
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The verification email could not be sent. Check your mail configuration and try again.');

                return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
            }

            return $this->redirectToRoute('app_auth_verify');
        }

        return $this->renderAuthPage(false, $loginData, $this->defaultRegisterData());
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getAuthenticatedUser($request) instanceof User) {
            return $this->redirectToRoute('app_home');
        }

        $registerData = [
            'full_name' => trim((string) $request->request->get('full_name', '')),
            'email' => trim((string) $request->request->get('email', '')),
            'role' => strtoupper(trim((string) $request->request->get('role', 'STUDENT'))),
            'phone' => trim((string) $request->request->get('phone', '')),
        ];

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');
            $registerData['email'] = $this->normalizeEmail($registerData['email']);

            $validationError = $this->validateRegistrationInput($registerData, $password, $confirmPassword);

            if ($validationError !== null) {
                $this->addFlash('error', $validationError);

                return $this->renderAuthPage(true, $this->defaultLoginData(), $registerData);
            }

            if ($this->userRepository->findOneBy(['email' => $registerData['email']]) instanceof User) {
                $this->addFlash('error', 'An account already exists for this email.');

                return $this->renderAuthPage(true, $this->defaultLoginData(), $registerData);
            }

            if (!$this->canStartVerification()) {
                $this->addFlash('error', 'Configure MAILER_DSN to send real verification emails.');

                return $this->renderAuthPage(true, $this->defaultLoginData(), $registerData);
            }

            try {
                $this->beginVerification(
                    $request->getSession(),
                    'register',
                    $registerData['email'],
                    [
                        'full_name' => $registerData['full_name'],
                        'email' => $registerData['email'],
                        'role' => $registerData['role'],
                        'phone' => $registerData['phone'],
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ],
                    'Verify your LearnAdapt account',
                    'Use this verification code to finish creating your LearnAdapt account.'
                );
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The verification email could not be sent. Check your mail configuration and try again.');

                return $this->renderAuthPage(true, $this->defaultLoginData(), $registerData);
            }

            return $this->redirectToRoute('app_auth_verify');
        }

        return $this->renderAuthPage(true, $this->defaultLoginData(), $registerData);
    }

    #[Route('/verify-code', name: 'app_auth_verify', methods: ['GET', 'POST'])]
    public function verifyCode(Request $request): Response
    {
        $pending = $this->getPendingVerification($request->getSession());

        if ($pending === null) {
            $this->addFlash('error', 'Start a login or registration flow before entering a verification code.');

            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $code = trim((string) $request->request->get('code', ''));

            if (!preg_match('/^\d{6}$/', $code)) {
                $this->addFlash('error', 'Enter the 6-digit verification code sent to your email.');

                return $this->renderVerificationPage($pending);
            }

            if ((int) $pending['expires_at'] < time()) {
                $request->getSession()->remove(self::VERIFICATION_SESSION_KEY);
                $this->addFlash('error', 'Your verification code expired. Request a new one.');

                return $this->redirectToRoute($pending['purpose'] === 'register' ? 'app_register' : 'app_login');
            }

            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            $pending['attempts'] = $attempts;
            $request->getSession()->set(self::VERIFICATION_SESSION_KEY, $pending);

            if ($attempts > self::MAX_CODE_ATTEMPTS) {
                $request->getSession()->remove(self::VERIFICATION_SESSION_KEY);
                $this->addFlash('error', 'Too many failed attempts. Start again to receive a new code.');

                return $this->redirectToRoute($pending['purpose'] === 'register' ? 'app_register' : 'app_login');
            }

            if (!password_verify($code, (string) $pending['code_hash'])) {
                $this->addFlash('error', 'The verification code is incorrect.');

                return $this->renderVerificationPage($pending);
            }

            $user = $this->completeVerification($request->getSession(), $pending);

            if (!$user instanceof User) {
                $this->addFlash('error', 'The verification flow could not be completed.');

                return $this->redirectToRoute('app_login');
            }

            $this->finishLogin($request->getSession(), $user);
            $this->addFlash('success', sprintf('Welcome, %s.', $user->getFullName() ?? $user->getEmail()));

            return $this->redirectToRoute('app_home');
        }

        return $this->renderVerificationPage($pending);
    }

    #[Route('/verify-code/resend', name: 'app_auth_verify_resend', methods: ['POST'])]
    public function resendVerificationCode(Request $request): Response
    {
        $pending = $this->getPendingVerification($request->getSession());

        if ($pending === null) {
            $this->addFlash('error', 'There is no active verification session to resend.');

            return $this->redirectToRoute('app_login');
        }

        if (!$this->canStartVerification()) {
            $this->addFlash('error', 'Configure MAILER_DSN to send real verification emails.');

            return $this->redirectToRoute('app_auth_verify');
        }

        $code = $this->generateVerificationCode();
        $pending['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $pending['expires_at'] = time() + self::VERIFICATION_TTL;
        $pending['attempts'] = 0;
        $pending['dev_code'] = $this->shouldExposeVerificationCodeInDev() ? $code : null;
        $request->getSession()->set(self::VERIFICATION_SESSION_KEY, $pending);

        if ($this->isMailerConfigured()) {
            try {
                $this->deliverVerificationEmail($pending['email'], $code, $pending['subject'], $pending['message']);
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The verification email could not be sent. Check your mail configuration and try again.');

                return $this->redirectToRoute('app_auth_verify');
            }

            $this->addFlash('success', 'A new verification code has been sent.');

            return $this->redirectToRoute('app_auth_verify');
        }

        $this->addFlash('success', 'Development mode is active. Use the updated on-screen verification code.');

        return $this->redirectToRoute('app_auth_verify');
    }

    #[Route('/connect/{provider}', name: 'app_auth_social', methods: ['GET'])]
    public function social(string $provider, Request $request): Response
    {
        $provider = strtolower($provider);

        if ($provider === 'google') {
            if (!$this->isGoogleConfigured()) {
                $this->addFlash('error', 'Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to enable Google sign-in.');

                return $this->redirectToRoute('app_login');
            }

            if (!$this->canStartVerification()) {
                $this->addFlash('error', 'Configure MAILER_DSN to send the verification code after Google sign-in.');

                return $this->redirectToRoute('app_login');
            }

            $state = bin2hex(random_bytes(16));
            $request->getSession()->set(self::GOOGLE_STATE_SESSION_KEY, $state);

            $query = http_build_query([
                'client_id' => $this->getGoogleClientId(),
                'redirect_uri' => $this->generateUrl('app_auth_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'access_type' => 'online',
                'prompt' => 'consent',
                'state' => $state,
            ]);

            return new RedirectResponse('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
        }

        if ($provider === 'github') {
            if (!$this->isGithubConfigured()) {
                $this->addFlash('error', 'Add GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET to enable GitHub sign-in.');

                return $this->redirectToRoute('app_login');
            }

            if (!$this->canStartVerification()) {
                $this->addFlash('error', 'Configure MAILER_DSN to send the verification code after GitHub sign-in.');

                return $this->redirectToRoute('app_login');
            }

            $state = bin2hex(random_bytes(16));
            $request->getSession()->set(self::GITHUB_STATE_SESSION_KEY, $state);

            $query = http_build_query([
                'client_id' => $this->getGithubClientId(),
                'redirect_uri' => $this->generateUrl('app_auth_github_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'scope' => 'read:user user:email',
                'state' => $state,
            ]);

            return new RedirectResponse('https://github.com/login/oauth/authorize?'.$query);
        }

        throw $this->createNotFoundException();
    }

    #[Route('/connect/google/callback', name: 'app_auth_google_callback', methods: ['GET'])]
    public function googleCallback(Request $request): Response
    {
        $session = $request->getSession();
        $state = (string) $request->query->get('state', '');
        $expectedState = (string) $session->get(self::GOOGLE_STATE_SESSION_KEY, '');
        $session->remove(self::GOOGLE_STATE_SESSION_KEY);

        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->addFlash('error', 'Google verification state was invalid. Try again.');

            return $this->redirectToRoute('app_login');
        }

        $code = (string) $request->query->get('code', '');

        if ($code === '') {
            $this->addFlash('error', 'Google did not return an authorization code.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $tokenResponse = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $this->getGoogleClientId(),
                    'client_secret' => $this->getGoogleClientSecret(),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->generateUrl('app_auth_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            ])->toArray(false);

            $accessToken = $tokenResponse['access_token'] ?? null;

            if (!is_string($accessToken) || $accessToken === '') {
                $this->addFlash('error', 'Google sign-in failed before verification could start.');

                return $this->redirectToRoute('app_login');
            }

            $profile = $this->httpClient->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'application/json',
                ],
            ])->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->addFlash('error', 'Google authentication could not be completed.');

            return $this->redirectToRoute('app_login');
        }

        $email = isset($profile['email']) ? $this->normalizeEmail((string) $profile['email']) : null;
        $emailVerified = (bool) ($profile['email_verified'] ?? false);

        if ($email === null || $email === '' || !$emailVerified) {
            $this->addFlash('error', 'Google did not provide a verified email address for this account.');

            return $this->redirectToRoute('app_login');
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);

        if ($existingUser instanceof User) {
            try {
                $this->beginVerification(
                    $session,
                    'login',
                    $email,
                    ['user_id' => $existingUser->getId()],
                    'Your LearnAdapt Google login code',
                    'Use this verification code to finish signing in with Google.'
                );
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The Google verification email could not be sent. Check your mail configuration and try again.');

                return $this->redirectToRoute('app_login');
            }

            return $this->redirectToRoute('app_auth_verify');
        }

        $fullName = trim((string) ($profile['name'] ?? $profile['given_name'] ?? 'Google User'));

        try {
            $this->beginVerification(
                $session,
                'register',
                $email,
                [
                    'full_name' => $fullName === '' ? 'Google User' : $fullName,
                    'email' => $email,
                    'role' => 'STUDENT',
                    'phone' => null,
                    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                ],
                'Verify your LearnAdapt Google sign-in',
                'Use this verification code to finish connecting your Google account to LearnAdapt.'
            );
        } catch (TransportExceptionInterface) {
            $this->addFlash('error', 'The Google verification email could not be sent. Check your mail configuration and try again.');

            return $this->redirectToRoute('app_login');
        }

        return $this->redirectToRoute('app_auth_verify');
    }

    #[Route('/connect/github/callback', name: 'app_auth_github_callback', methods: ['GET'])]
    public function githubCallback(Request $request): Response
    {
        $session = $request->getSession();
        $state = (string) $request->query->get('state', '');
        $expectedState = (string) $session->get(self::GITHUB_STATE_SESSION_KEY, '');
        $session->remove(self::GITHUB_STATE_SESSION_KEY);

        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->addFlash('error', 'GitHub verification state was invalid. Try again.');

            return $this->redirectToRoute('app_login');
        }

        $code = (string) $request->query->get('code', '');

        if ($code === '') {
            $this->addFlash('error', 'GitHub did not return an authorization code.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $tokenResponse = $this->httpClient->request('POST', 'https://github.com/login/oauth/access_token', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'client_id' => $this->getGithubClientId(),
                    'client_secret' => $this->getGithubClientSecret(),
                    'code' => $code,
                    'redirect_uri' => $this->generateUrl('app_auth_github_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'state' => $state,
                ],
            ])->toArray(false);

            $accessToken = $tokenResponse['access_token'] ?? null;

            if (!is_string($accessToken) || $accessToken === '') {
                $this->addFlash('error', 'GitHub sign-in failed before verification could start.');

                return $this->redirectToRoute('app_login');
            }

            $headers = [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'LearnAdapt',
            ];

            $profile = $this->httpClient->request('GET', 'https://api.github.com/user', [
                'headers' => $headers,
            ])->toArray(false);

            $emails = $this->httpClient->request('GET', 'https://api.github.com/user/emails', [
                'headers' => $headers,
            ])->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->addFlash('error', 'GitHub authentication could not be completed.');

            return $this->redirectToRoute('app_login');
        }

        $email = $this->extractGithubEmail($emails);

        if ($email === null) {
            $this->addFlash('error', 'GitHub did not provide a verified email address for this account.');

            return $this->redirectToRoute('app_login');
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);

        if ($existingUser instanceof User) {
            try {
                $this->beginVerification(
                    $session,
                    'login',
                    $email,
                    ['user_id' => $existingUser->getId()],
                    'Your LearnAdapt GitHub login code',
                    'Use this verification code to finish signing in with GitHub.'
                );
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The GitHub verification email could not be sent. Check your mail configuration and try again.');

                return $this->redirectToRoute('app_login');
            }
        } else {
            $fullName = trim((string) ($profile['name'] ?? $profile['login'] ?? 'GitHub User'));

            try {
                $this->beginVerification(
                    $session,
                    'register',
                    $email,
                    [
                        'full_name' => $fullName === '' ? 'GitHub User' : $fullName,
                        'email' => $email,
                        'role' => 'STUDENT',
                        'phone' => null,
                        'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    ],
                    'Verify your LearnAdapt GitHub sign-in',
                    'Use this verification code to finish connecting your GitHub account to LearnAdapt.'
                );
            } catch (TransportExceptionInterface) {
                $this->addFlash('error', 'The GitHub verification email could not be sent. Check your mail configuration and try again.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->redirectToRoute('app_auth_verify');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST', 'GET'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove(self::AUTH_SESSION_KEY);
        $request->getSession()->remove(self::VERIFICATION_SESSION_KEY);
        $request->getSession()->remove(self::GOOGLE_STATE_SESSION_KEY);
        $request->getSession()->remove(self::GITHUB_STATE_SESSION_KEY);

        $this->addFlash('success', 'You have been signed out.');

        return $this->redirectToRoute('app_login');
    }

    private function renderAuthPage(bool $showRegister, array $loginData, array $registerData): Response
    {
        return $this->render('auth/login.html.twig', [
            'showRegister' => $showRegister,
            'loginData' => $loginData,
            'registerData' => $registerData,
            'roleOptions' => self::PUBLIC_ROLES,
        ]);
    }

    private function renderVerificationPage(array $pending): Response
    {
        return $this->render('auth/verify_code.html.twig', [
            'email' => $pending['email'],
            'purpose' => $pending['purpose'],
            'devVerificationCode' => $this->shouldExposeVerificationCodeInDev() ? ($pending['dev_code'] ?? null) : null,
        ]);
    }

    private function defaultLoginData(): array
    {
        return ['email' => ''];
    }

    private function defaultRegisterData(): array
    {
        return [
            'full_name' => '',
            'email' => '',
            'role' => 'STUDENT',
            'phone' => '',
        ];
    }

    private function validateRegistrationInput(array $registerData, string $password, string $confirmPassword): ?string
    {
        if ($registerData['full_name'] === '' || $registerData['email'] === '' || $registerData['role'] === '' || $registerData['phone'] === '' || $password === '' || $confirmPassword === '') {
            return 'Fill in your name, email, role, phone number, and password.';
        }

        if (!filter_var($registerData['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        if (mb_strlen($registerData['full_name']) < 2 || mb_strlen($registerData['full_name']) > 160) {
            return 'Your full name must be between 2 and 160 characters.';
        }

        if (!in_array($registerData['role'], self::PUBLIC_ROLES, true)) {
            return 'Choose a valid role.';
        }

        if (!preg_match('/^\+?[0-9\s\-]{8,20}$/', $registerData['phone'])) {
            return 'Enter a valid phone number.';
        }

        if (mb_strlen($password) < 8) {
            return 'Your password must contain at least 8 characters.';
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Your password must contain letters and numbers.';
        }

        if ($password !== $confirmPassword) {
            return 'Password confirmation does not match.';
        }

        return null;
    }

    private function verifyUserPassword(User $user, string $plainPassword): bool
    {
        $storedHash = (string) ($user->getPasswordHash() ?? $user->getPassword_hash() ?? '');

        if ($storedHash === '') {
            return false;
        }

        $passwordInfo = password_get_info($storedHash);

        if (($passwordInfo['algo'] ?? null) !== null && password_verify($plainPassword, $storedHash)) {
            if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                $user->setPasswordHash(password_hash($plainPassword, PASSWORD_DEFAULT));
                $this->entityManager->flush();
            }

            return true;
        }

        $legacyHash = base64_encode(hash('sha256', $plainPassword, true));

        if (hash_equals($storedHash, $legacyHash)) {
            $user->setPasswordHash(password_hash($plainPassword, PASSWORD_DEFAULT));
            $this->entityManager->flush();

            return true;
        }

        return false;
    }

    private function beginVerification(SessionInterface $session, string $purpose, string $email, array $payload, string $subject, string $message): void
    {
        $code = $this->generateVerificationCode();

        $session->set(self::VERIFICATION_SESSION_KEY, [
            'purpose' => $purpose,
            'email' => $email,
            'payload' => $payload,
            'subject' => $subject,
            'message' => $message,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'dev_code' => $this->shouldExposeVerificationCodeInDev() ? $code : null,
            'expires_at' => time() + self::VERIFICATION_TTL,
            'attempts' => 0,
        ]);

        if ($this->isMailerConfigured()) {
            $this->deliverVerificationEmail($email, $code, $subject, $message);
            $this->addFlash('success', sprintf('A verification code has been sent to %s.', $email));

            return;
        }

        $this->addFlash('success', sprintf('Development mode is active. Email delivery is not configured, so use the on-screen verification code for %s.', $email));
    }

    private function deliverVerificationEmail(string $email, string $code, string $subject, string $message): void
    {
        $mail = (new Email())
            ->from($this->getMailerFromAddress())
            ->to($email)
            ->subject($subject)
            ->text($message."\n\nVerification code: {$code}\n\nThis code expires in 10 minutes.")
            ->html(sprintf(
                '<div style="font-family:Arial,sans-serif;background:#06182f;color:#f3f8ff;padding:24px;border-radius:16px"><h2 style="margin:0 0 12px">LearnAdapt Verification</h2><p style="margin:0 0 16px">%s</p><div style="font-size:32px;font-weight:800;letter-spacing:0.25em;margin:18px 0;color:#79f0ff">%s</div><p style="margin:0;color:#b8d7ef">This code expires in 10 minutes.</p></div>',
                htmlspecialchars($message, ENT_QUOTES),
                htmlspecialchars($code, ENT_QUOTES)
            ));

        $this->mailer->send($mail);
    }

    private function completeVerification(SessionInterface $session, array $pending): ?User
    {
        $session->remove(self::VERIFICATION_SESSION_KEY);

        if ($pending['purpose'] === 'login') {
            $userId = (int) ($pending['payload']['user_id'] ?? 0);
            $user = $this->userRepository->find($userId);

            if (!$user instanceof User) {
                return null;
            }

            $user->setLastLogin(new \DateTime());
            $user->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            return $user;
        }

        if ($pending['purpose'] === 'register') {
            $payload = $pending['payload'] ?? [];

            if ($this->userRepository->findOneBy(['email' => $payload['email'] ?? '']) instanceof User) {
                return null;
            }

            $user = (new User())
                ->setEmail((string) $payload['email'])
                ->setPasswordHash((string) $payload['password_hash'])
                ->setFullName((string) $payload['full_name'])
                ->setRole((string) $payload['role'])
                ->setPhone($payload['phone'] === null ? null : (string) $payload['phone'])
                ->setIsActive(true)
                ->setCreatedAt(new \DateTime())
                ->setUpdatedAt(new \DateTime())
                ->setLastLogin(new \DateTime());

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $user;
        }

        return null;
    }

    private function finishLogin(SessionInterface $session, User $user): void
    {
        $session->set(self::AUTH_SESSION_KEY, [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'full_name' => $user->getFullName(),
            'role' => $user->getRole(),
        ]);
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);

        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }

        return $this->userRepository->find((int) $auth['id']);
    }

    private function getPendingVerification(SessionInterface $session): ?array
    {
        $pending = $session->get(self::VERIFICATION_SESSION_KEY);

        return is_array($pending) ? $pending : null;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function generateVerificationCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function isMailerConfigured(): bool
    {
        $dsn = (string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? '');

        return $dsn !== '' && !str_starts_with($dsn, 'null://');
    }

    private function canStartVerification(): bool
    {
        return $this->isMailerConfigured() || $this->isDevVerificationFallbackEnabled();
    }

    private function isDevVerificationFallbackEnabled(): bool
    {
        return (string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'dev') === 'dev';
    }

    private function shouldExposeVerificationCodeInDev(): bool
    {
        return !$this->isMailerConfigured() && $this->isDevVerificationFallbackEnabled();
    }

    private function getMailerFromAddress(): string
    {
        $from = trim((string) ($_ENV['MAILER_FROM'] ?? $_SERVER['MAILER_FROM'] ?? ''));

        return $from !== '' ? $from : 'no-reply@example.com';
    }

    private function isGoogleConfigured(): bool
    {
        return $this->getGoogleClientId() !== '' && $this->getGoogleClientSecret() !== '';
    }

    private function getGoogleClientId(): string
    {
        return trim((string) ($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID'] ?? ''));
    }

    private function getGoogleClientSecret(): string
    {
        return trim((string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET'] ?? ''));
    }

    private function isGithubConfigured(): bool
    {
        return $this->getGithubClientId() !== '' && $this->getGithubClientSecret() !== '';
    }

    private function getGithubClientId(): string
    {
        return trim((string) ($_ENV['GITHUB_CLIENT_ID'] ?? $_SERVER['GITHUB_CLIENT_ID'] ?? ''));
    }

    private function getGithubClientSecret(): string
    {
        return trim((string) ($_ENV['GITHUB_CLIENT_SECRET'] ?? $_SERVER['GITHUB_CLIENT_SECRET'] ?? ''));
    }

    private function extractGithubEmail(array $emails): ?string
    {
        foreach ($emails as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['verified'] ?? false) && ($item['primary'] ?? false) && isset($item['email'])) {
                return $this->normalizeEmail((string) $item['email']);
            }
        }

        foreach ($emails as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['verified'] ?? false) && isset($item['email'])) {
                return $this->normalizeEmail((string) $item['email']);
            }
        }

        return null;
    }
}