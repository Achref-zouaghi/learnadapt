<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SettingsController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
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

    #[Route('/settings/{section}', name: 'app_settings', defaults: ['section' => 'account'], methods: ['GET'])]
    public function index(Request $request, string $section): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $conn = $this->entityManager->getConnection();
        $userId = $user->getId();

        // Notification preferences (stored as JSON in user table or defaults)
        $notifPrefs = [
            'incident_alerts' => true,
            'cohort_summaries' => false,
            'weekly_reports' => true,
            'forum_replies' => true,
            'quiz_results' => true,
        ];

        // Account stats for display
        $memberSince = $user->getCreated_at();
        $lastLogin = $user->getLast_login();

        // Count user data for data-export section
        $coursesCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM courses WHERE teacher_user_id = ?', [$userId]);
        $quizAttemptsCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM quiz_attempts WHERE student_user_id = ?', [$userId]);
        $forumPostsCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM forum_posts WHERE author_user_id = ?', [$userId]);
        $tasksCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM tasks WHERE created_by_teacher_id = ?', [$userId]);
        $notificationsCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);

        // Payment methods
        $paymentMethods = $conn->fetchAllAssociative(
            'SELECT * FROM user_payment_methods WHERE user_id = ? ORDER BY is_default DESC, created_at DESC',
            [$userId]
        );

        return $this->render('settings/index.html.twig', [
            'section' => $section,
            'user' => $user,
            'notifPrefs' => $notifPrefs,
            'memberSince' => $memberSince,
            'lastLogin' => $lastLogin,
            'coursesCount' => $coursesCount,
            'quizAttemptsCount' => $quizAttemptsCount,
            'forumPostsCount' => $forumPostsCount,
            'tasksCount' => $tasksCount,
            'notificationsCount' => $notificationsCount,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    #[Route('/settings/update-account', name: 'app_settings_update_account', methods: ['POST'])]
    public function updateAccount(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $fullName = trim($request->request->get('full_name', ''));
        $phone = trim($request->request->get('phone', ''));
        $bio = trim($request->request->get('bio', ''));
        $location = trim($request->request->get('location', ''));

        if ($fullName !== '') {
            $user->setFull_name($fullName);
        }
        $user->setPhone($phone ?: null);
        $user->setBio($bio ?: null);
        $user->setLocation($location ?: null);
        $user->setUpdated_at(new \DateTime());

        $this->entityManager->flush();

        $this->addFlash('success', 'flash.account_updated');
        return $this->redirectToRoute('app_settings', ['section' => 'account']);
    }

    #[Route('/settings/update-password', name: 'app_settings_update_password', methods: ['POST'])]
    public function updatePassword(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $currentPassword = $request->request->get('current_password', '');
        $newPassword = $request->request->get('new_password', '');
        $confirmPassword = $request->request->get('confirm_password', '');

        if (!password_verify($currentPassword, $user->getPassword_hash())) {
            $this->addFlash('error', 'flash.password_incorrect');
            return $this->redirectToRoute('app_settings', ['section' => 'password']);
        }

        if (strlen($newPassword) < 6) {
            $this->addFlash('error', 'flash.password_min');
            return $this->redirectToRoute('app_settings', ['section' => 'password']);
        }

        if ($newPassword !== $confirmPassword) {
            $this->addFlash('error', 'flash.password_mismatch');
            return $this->redirectToRoute('app_settings', ['section' => 'password']);
        }

        $user->setPassword_hash(password_hash($newPassword, PASSWORD_BCRYPT));
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.password_updated');
        return $this->redirectToRoute('app_settings', ['section' => 'password']);
    }

    #[Route('/settings/update-avatar', name: 'app_settings_update_avatar', methods: ['POST'])]
    public function updateAvatar(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $file = $request->files->get('avatar');
        if ($file && $file->isValid()) {
            $mime = $file->getMimeType();
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                $this->addFlash('error', 'flash.invalid_image');
                return $this->redirectToRoute('app_settings', ['section' => 'account']);
            }
            if ($file->getSize() > 2 * 1024 * 1024) {
                $this->addFlash('error', 'flash.image_too_large');
                return $this->redirectToRoute('app_settings', ['section' => 'account']);
            }
            $data = base64_encode(file_get_contents($file->getPathname()));
            $user->setAvatar_base64("data:{$mime};base64,{$data}");
            $user->setUpdated_at(new \DateTime());
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.avatar_updated');
        }

        return $this->redirectToRoute('app_settings', ['section' => 'account']);
    }

    #[Route('/settings/remove-avatar', name: 'app_settings_remove_avatar', methods: ['POST'])]
    public function removeAvatar(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $user->setAvatar_base64(null);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.avatar_removed');
        return $this->redirectToRoute('app_settings', ['section' => 'account']);
    }

    #[Route('/settings/delete-account', name: 'app_settings_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $confirmEmail = trim($request->request->get('confirm_email', ''));
        if ($confirmEmail !== $user->getEmail()) {
            $this->addFlash('error', 'flash.email_mismatch');
            return $this->redirectToRoute('app_settings', ['section' => 'account']);
        }

        $request->getSession()->invalidate();
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_login');
    }

    #[Route('/settings/update-language', name: 'app_settings_update_language', methods: ['POST'])]
    public function updateLanguage(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $locale = $request->request->get('locale', 'en');
        $allowed = ['en', 'fr', 'es'];
        if (!in_array($locale, $allowed, true)) {
            $locale = 'en';
        }

        $user->setLocale($locale);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        // Update session locale so it takes effect immediately
        $request->getSession()->set('_locale', $locale);

        $this->addFlash('success', 'flash.language_updated');
        return $this->redirectToRoute('app_settings', ['section' => 'language']);
    }

    #[Route('/settings/update-theme', name: 'app_settings_update_theme', methods: ['POST'])]
    public function updateTheme(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $theme = $request->request->get('theme', 'dark');
        $allowed = ['light', 'dark'];
        if (!in_array($theme, $allowed, true)) {
            $theme = 'dark';
        }

        $user->setTheme($theme);
        $user->setUpdated_at(new \DateTime());
        $this->entityManager->flush();

        $request->getSession()->set('_theme', $theme);

        $this->addFlash('success', 'flash.theme_updated');
        return $this->redirectToRoute('app_settings', ['section' => 'appearance']);
    }

    // ── PAYMENT METHODS ──────────────────────────────────────────────────────

    #[Route('/settings/payment/add', name: 'app_settings_payment_add', methods: ['POST'])]
    public function addPaymentMethod(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $cardHolder  = trim($request->request->get('card_holder', ''));
        $cardNumber  = preg_replace('/\D/', '', $request->request->get('card_number', ''));
        $expiryMonth = trim($request->request->get('expiry_month', ''));
        $expiryYear  = trim($request->request->get('expiry_year', ''));
        $cvv         = trim($request->request->get('cvv', ''));

        // Basic validation
        if ($cardHolder === '' || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            $this->addFlash('error', 'Invalid card details.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // Luhn check
        if (!$this->luhnCheck($cardNumber)) {
            $this->addFlash('error', 'Invalid card number.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // Whitelist check — only known test cards accepted
        if (!in_array($cardNumber, self::VALID_TEST_CARDS, true)) {
            $this->addFlash('error', 'Card not recognized. Please use a valid test card number.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // Expiry validation
        $month = (int) $expiryMonth;
        $year  = (int) ('20' . $expiryYear);
        if ($month < 1 || $month > 12 || $year < (int) date('Y') || ($year === (int) date('Y') && $month < (int) date('n'))) {
            $this->addFlash('error', 'Card is expired or expiry date is invalid.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // CVV
        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            $this->addFlash('error', 'Invalid CVV.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // Simulate declined test card
        if ($cardNumber === '4000000000000002') {
            $this->addFlash('error', 'Your card was declined. Please try a different card.');
            return $this->redirectToRoute('app_settings', ['section' => 'payment']);
        }

        // Detect brand
        $brand = $this->detectCardBrand($cardNumber);
        $last4 = substr($cardNumber, -4);

        $conn   = $this->entityManager->getConnection();
        $userId = $user->getId();

        // If first card, make it default
        $count    = (int) $conn->fetchOne('SELECT COUNT(*) FROM user_payment_methods WHERE user_id = ?', [$userId]);
        $isDefault = $count === 0 ? 1 : 0;

        $conn->executeStatement(
            'INSERT INTO user_payment_methods (user_id, card_holder, card_last4, card_brand, expiry_month, expiry_year, is_default, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $cardHolder, $last4, $brand, str_pad((string)$month, 2, '0', STR_PAD_LEFT), substr((string)$year, -2), $isDefault]
        );

        $this->addFlash('success', 'Card added successfully!');
        return $this->redirectToRoute('app_settings', ['section' => 'payment']);
    }

    #[Route('/settings/payment/remove/{id}', name: 'app_settings_payment_remove', methods: ['POST'])]
    public function removePaymentMethod(Request $request, int $id): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'DELETE FROM user_payment_methods WHERE id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        $this->addFlash('success', 'Card removed.');
        return $this->redirectToRoute('app_settings', ['section' => 'payment']);
    }

    #[Route('/settings/payment/default/{id}', name: 'app_settings_payment_default', methods: ['POST'])]
    public function setDefaultPaymentMethod(Request $request, int $id): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $conn   = $this->entityManager->getConnection();
        $userId = $user->getId();

        $conn->executeStatement('UPDATE user_payment_methods SET is_default = 0 WHERE user_id = ?', [$userId]);
        $conn->executeStatement('UPDATE user_payment_methods SET is_default = 1 WHERE id = ? AND user_id = ?', [$id, $userId]);

        $this->addFlash('success', 'Default card updated.');
        return $this->redirectToRoute('app_settings', ['section' => 'payment']);
    }

    // Known test card numbers (Stripe published test cards)
    private const VALID_TEST_CARDS = [
        // Visa
        '4242424242424242',   // success
        '4000056655665556',   // Visa debit
        '4000000000000002',   // declined
        '4000000000009995',   // insufficient funds
        '4000000000000069',   // expired
        '4000000000000127',   // incorrect CVC
        '4000000000003220',   // 3D Secure
        '4000002500003155',   // 3D Secure required
        // Mastercard
        '5555555555554444',
        '2223003122003222',
        '5200828282828210',   // Mastercard debit
        '5105105105105100',   // Mastercard prepaid
        // American Express
        '378282246310005',
        '371449635398431',
        // Discover
        '6011111111111117',
        '6011000990139424',
        // Diners Club
        '3056930009020004',
        '36227206271667',
        // JCB
        '3566002020360505',
        // UnionPay
        '6200000000000005',
    ];

    private function luhnCheck(string $number): bool
    {
        $sum    = 0;
        $nDigit = strlen($number);
        $parity = $nDigit % 2;
        for ($i = 0; $i < $nDigit; $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }

    private function detectCardBrand(string $number): string
    {
        if (preg_match('/^4/', $number))                             return 'Visa';
        if (preg_match('/^5[1-5]/', $number))                       return 'Mastercard';
        if (preg_match('/^3[47]/', $number))                        return 'Amex';
        if (preg_match('/^6(?:011|5)/', $number))                   return 'Discover';
        if (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) return 'JCB';
        return 'Card';
    }
}
