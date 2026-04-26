<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebAuthnController extends AbstractController
{
    private const AUTH_SESSION_KEY  = 'auth.user';
    private const WEBAUTHN_CHALLENGE = 'webauthn.challenge';
    private const WEBAUTHN_UID      = 'webauthn.pending_uid';
    private const RP_NAME           = 'LearnAdapt';

    public function __construct(
        private readonly UserRepository       $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Step 1 (register): generate options for navigator.credentials.create()
     * Requires the user to be logged in normally first.
     */
    #[Route('/auth/webauthn/register-options', name: 'webauthn_register_options', methods: ['GET'])]
    public function registerOptions(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $user = $this->userRepository->find((int) $auth['id']);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        $webAuthn = $this->buildWebAuthn($request);

        // Don't require resident key / user verification for simplicity
        $createArgs = $webAuthn->getCreateArgs(
            \hex2bin(\str_pad(\dechex($user->getId()), 16, '0', STR_PAD_LEFT)),
            $user->getEmail(),
            $user->getFullName(),
            60,   // timeout seconds
            false, // requireResidentKey
            'preferred', // userVerification
            null  // crossPlatformAttachment: null = both
        );

        $request->getSession()->set(self::WEBAUTHN_CHALLENGE, $webAuthn->getChallenge());

        return new JsonResponse($createArgs);
    }

    /**
     * Step 2 (register): process the attestation response and store credential.
     */
    #[Route('/auth/webauthn/register', name: 'webauthn_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $challenge = $request->getSession()->get(self::WEBAUTHN_CHALLENGE);
        if (!$challenge instanceof ByteBuffer) {
            return new JsonResponse(['error' => 'No challenge in session'], 400);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        try {
            $webAuthn = $this->buildWebAuthn($request);

            $clientDataJSON    = base64_decode($body['clientDataJSON'] ?? '');
            $attestationObject = base64_decode($body['attestationObject'] ?? '');

            $data = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);

            $credentialId = base64_encode($data->credentialId);
            $publicKey    = $data->credentialPublicKey;

            $conn   = $this->entityManager->getConnection();
            $userId = (int) $auth['id'];

            // Check for duplicate
            $existing = $conn->fetchOne(
                'SELECT id FROM webauthn_credentials WHERE credential_id = ?',
                [$credentialId]
            );

            if ($existing === false) {
                $conn->executeStatement(
                    'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, counter) VALUES (?, ?, ?, 0)',
                    [$userId, $credentialId, $publicKey]
                );
            }

            $request->getSession()->remove(self::WEBAUTHN_CHALLENGE);

            return new JsonResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Step 1 (login): generate options for navigator.credentials.get()
     */
    #[Route('/auth/webauthn/login-options', name: 'webauthn_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $body  = json_decode($request->getContent(), true);
        $email = trim(strtolower((string) ($body['email'] ?? '')));

        if ($email === '') {
            return new JsonResponse(['error' => 'Email required'], 400);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            // Don't reveal user existence - still return plausible error
            return new JsonResponse(['error' => 'No passkey registered for this account'], 404);
        }

        $conn = $this->entityManager->getConnection();
        $credentials = $conn->fetchAllAssociative(
            'SELECT credential_id FROM webauthn_credentials WHERE user_id = ?',
            [$user->getId()]
        );

        if (count($credentials) === 0) {
            return new JsonResponse(['error' => 'No passkey registered for this account'], 404);
        }

        $webAuthn = $this->buildWebAuthn($request);

        $credIds = array_map(
            fn($row) => base64_decode($row['credential_id']),
            $credentials
        );

        $getArgs = $webAuthn->getGetArgs(
            $credIds,
            60,
            true, true, true, true, // allowed transports
            'preferred'
        );

        $request->getSession()->set(self::WEBAUTHN_CHALLENGE, $webAuthn->getChallenge());
        $request->getSession()->set(self::WEBAUTHN_UID, $user->getId());

        return new JsonResponse($getArgs);
    }

    /**
     * Step 2 (login): verify assertion and create session.
     */
    #[Route('/auth/webauthn/login-verify', name: 'webauthn_login_verify', methods: ['POST'])]
    public function loginVerify(Request $request): JsonResponse
    {
        $challenge = $request->getSession()->get(self::WEBAUTHN_CHALLENGE);
        $userId    = $request->getSession()->get(self::WEBAUTHN_UID);

        if (!$challenge instanceof ByteBuffer || !is_int($userId)) {
            return new JsonResponse(['error' => 'Session expired, please try again'], 400);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        try {
            $credentialId = base64_encode(base64_decode($body['rawId'] ?? ''));

            $conn = $this->entityManager->getConnection();
            $row  = $conn->fetchAssociative(
                'SELECT id, public_key, counter FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?',
                [$credentialId, $userId]
            );

            if ($row === false) {
                return new JsonResponse(['error' => 'Credential not found'], 404);
            }

            $clientDataJSON    = base64_decode($body['clientDataJSON'] ?? '');
            $authenticatorData = base64_decode($body['authenticatorData'] ?? '');
            $signature         = base64_decode($body['signature'] ?? '');
            $userHandle        = $body['userHandle'] ?? null;

            $webAuthn = $this->buildWebAuthn($request);

            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $row['public_key'],
                $challenge,
                (int) $row['counter'],
                false,
                true
            );

            $newCounter = $webAuthn->getSignatureCounter() ?? (int) $row['counter'];

            // Update counter
            $conn->executeStatement(
                'UPDATE webauthn_credentials SET counter = ? WHERE id = ?',
                [$newCounter, $row['id']]
            );

            $user = $this->userRepository->find($userId);
            if ($user === null) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }

            // Log the user in
            $request->getSession()->remove(self::WEBAUTHN_CHALLENGE);
            $request->getSession()->remove(self::WEBAUTHN_UID);
            $request->getSession()->set(self::AUTH_SESSION_KEY, [
                'id'        => $user->getId(),
                'email'     => $user->getEmail(),
                'full_name' => $user->getFullName(),
                'role'      => $user->getRole(),
            ]);

            return new JsonResponse(['status' => 'ok', 'redirect' => '/']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    // ── Check if user has passkeys ────────────────────────────────────────────

    #[Route('/auth/webauthn/status', name: 'webauthn_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['registered' => false]);
        }

        $conn  = $this->entityManager->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?',
            [(int) $auth['id']]
        );

        return new JsonResponse(['registered' => $count > 0]);
    }

    // ── Delete all passkeys ───────────────────────────────────────────────────

    #[Route('/auth/webauthn/delete', name: 'webauthn_delete', methods: ['POST'])]
    public function delete(Request $request): JsonResponse
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'DELETE FROM webauthn_credentials WHERE user_id = ?',
            [(int) $auth['id']]
        );

        return new JsonResponse(['status' => 'ok']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function buildWebAuthn(Request $request): WebAuthn
    {
        $host = $request->getHost();
        // Browsers reject IP addresses as WebAuthn RP ID — map 127.0.0.1 to localhost
        if ($host === '127.0.0.1') {
            $host = 'localhost';
        }
        // null = allow all formats; true = use base64url encoding
        return new WebAuthn(self::RP_NAME, $host, null, true);
    }
}
