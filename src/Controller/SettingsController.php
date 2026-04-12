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
}
