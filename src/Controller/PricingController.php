<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class PricingController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';

    private const PLANS = [
        'starter' => [
            'name'     => 'Starter',
            'monthly'  => 29.00,
            'annual'   => 23.00,
            'color'    => '#6b7280',
            'features' => [
                '5 courses',
                'Adaptive learning paths',
                'Analytics dashboard',
                'AI assistant (50 queries/mo)',
                '2 certificates',
            ],
        ],
        'pro' => [
            'name'     => 'Pro',
            'monthly'  => 79.00,
            'annual'   => 63.00,
            'color'    => '#7b61f1',
            'popular'  => true,
            'features' => [
                'Unlimited courses',
                'Adaptive learning paths',
                'Advanced analytics',
                'AI assistant (unlimited)',
                'Unlimited certificates',
                'Priority support',
            ],
        ],
        'teams' => [
            'name'     => 'Teams',
            'monthly'  => 149.00,
            'annual'   => 119.00,
            'color'    => '#059669',
            'features' => [
                'Everything in Pro',
                'Team analytics & reporting',
                'SSO & SCIM',
                'Dedicated success manager',
                'Custom course builder',
                'SLA guarantee',
            ],
        ],
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
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

    #[Route('/pricing', name: 'app_pricing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $conn = $this->entityManager->getConnection();
        $currentPlan = $conn->fetchOne('SELECT plan FROM users WHERE id = ?', [$user->getId()]) ?: 'free';

        return $this->render('pricing/index.html.twig', [
            'user'        => $user,
            'plans'       => self::PLANS,
            'currentPlan' => $currentPlan,
        ]);
    }

    #[Route('/pricing/checkout/{plan}/{cycle}', name: 'app_checkout', methods: ['GET'])]
    public function checkout(Request $request, string $plan, string $cycle): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!isset(self::PLANS[$plan]) || !in_array($cycle, ['monthly', 'annual'], true)) {
            return $this->redirectToRoute('app_pricing');
        }

        $planData = self::PLANS[$plan];
        $amount   = $cycle === 'annual' ? $planData['annual'] : $planData['monthly'];

        $conn = $this->entityManager->getConnection();
        $paymentMethods = $conn->fetchAllAssociative(
            'SELECT * FROM user_payment_methods WHERE user_id = ? ORDER BY is_default DESC, created_at DESC',
            [$user->getId()]
        );

        return $this->render('pricing/checkout.html.twig', [
            'user'           => $user,
            'planKey'        => $plan,
            'planData'       => $planData,
            'cycle'          => $cycle,
            'amount'         => $amount,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    #[Route('/pricing/checkout/{plan}/{cycle}', name: 'app_checkout_process', methods: ['POST'])]
    public function processCheckout(Request $request, string $plan, string $cycle): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!isset(self::PLANS[$plan]) || !in_array($cycle, ['monthly', 'annual'], true)) {
            return $this->redirectToRoute('app_pricing');
        }

        $planData        = self::PLANS[$plan];
        $amount          = $cycle === 'annual' ? $planData['annual'] : $planData['monthly'];
        $paymentMethodId = (int) $request->request->get('payment_method_id', 0);

        $conn   = $this->entityManager->getConnection();
        $userId = $user->getId();

        // Verify payment method belongs to this user
        $card = $conn->fetchAssociative(
            'SELECT * FROM user_payment_methods WHERE id = ? AND user_id = ?',
            [$paymentMethodId, $userId]
        );

        if (!$card) {
            $this->addFlash('error', 'Please select a valid payment method.');
            return $this->redirectToRoute('app_checkout', ['plan' => $plan, 'cycle' => $cycle]);
        }

        // Simulate processing delay + success
        // (declined card simulation: last4 = 0002)
        if ($card['card_last4'] === '0002') {
            $this->addFlash('error', 'Your card was declined. Please use a different card.');
            return $this->redirectToRoute('app_checkout', ['plan' => $plan, 'cycle' => $cycle]);
        }

        // Calculate expiry
        $expiresAt = new \DateTime();
        if ($cycle === 'annual') {
            $expiresAt->modify('+1 year');
        } else {
            $expiresAt->modify('+1 month');
        }

        // Update user plan
        $conn->executeStatement(
            'UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?',
            [$plan, $expiresAt->format('Y-m-d H:i:s'), $userId]
        );

        // Record subscription
        $conn->executeStatement(
            'INSERT INTO user_subscriptions (user_id, plan, billing_cycle, amount, currency, payment_method_id, status, started_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [$userId, $plan, $cycle, $amount, 'USD', $paymentMethodId, 'active', $expiresAt->format('Y-m-d H:i:s')]
        );

        // Send receipt email
        try {
            $userEmail  = $user->getEmail();
            $userName   = $user->getFullName() ?? $userEmail;
            $planLabel  = $planData['name'];
            $cycleLabel = ucfirst($cycle);
            $amountFmt  = number_format($amount, 2);
            $expFmt     = $expiresAt->format('F j, Y');
            $last4      = $card['card_last4'];
            $brand      = $card['card_brand'];

            $html = '
<div style="font-family:\'Helvetica Neue\',Arial,sans-serif;background:#06101f;color:#e8f0fc;padding:0;margin:0">
  <div style="max-width:560px;margin:0 auto;padding:40px 24px">
    <div style="text-align:center;margin-bottom:32px">
      <div style="display:inline-block;background:linear-gradient(135deg,#7856ed,#3cc5d9);border-radius:14px;padding:14px 28px">
        <span style="color:#fff;font-size:1.3rem;font-weight:900;letter-spacing:-0.03em">LearnAdapt</span>
      </div>
    </div>
    <div style="background:#0d1b2e;border:1px solid rgba(255,255,255,0.08);border-radius:20px;padding:32px">
      <div style="text-align:center;margin-bottom:28px">
        <div style="font-size:3rem;margin-bottom:12px">✅</div>
        <h1 style="margin:0 0 8px;font-size:1.6rem;font-weight:900;color:#fff">Payment confirmed!</h1>
        <p style="margin:0;color:#9aa9c0;font-size:0.95rem">Hi ' . htmlspecialchars($userName, ENT_QUOTES) . ', your <strong style="color:#a78bfa">' . htmlspecialchars($planLabel, ENT_QUOTES) . '</strong> plan is now active.</p>
      </div>
      <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:20px;margin-bottom:24px">
        <table style="width:100%;border-collapse:collapse;font-size:0.9rem">
          <tr style="border-bottom:1px solid rgba(255,255,255,0.07)">
            <td style="padding:11px 0;color:#9aa9c0">Plan</td>
            <td style="padding:11px 0;color:#fff;font-weight:700;text-align:right">' . htmlspecialchars($planLabel, ENT_QUOTES) . '</td>
          </tr>
          <tr style="border-bottom:1px solid rgba(255,255,255,0.07)">
            <td style="padding:11px 0;color:#9aa9c0">Billing cycle</td>
            <td style="padding:11px 0;color:#fff;font-weight:700;text-align:right">' . htmlspecialchars($cycleLabel, ENT_QUOTES) . '</td>
          </tr>
          <tr style="border-bottom:1px solid rgba(255,255,255,0.07)">
            <td style="padding:11px 0;color:#9aa9c0">Amount charged</td>
            <td style="padding:11px 0;color:#fff;font-weight:700;text-align:right">$' . $amountFmt . '</td>
          </tr>
          <tr style="border-bottom:1px solid rgba(255,255,255,0.07)">
            <td style="padding:11px 0;color:#9aa9c0">Payment method</td>
            <td style="padding:11px 0;color:#fff;font-weight:700;text-align:right">' . htmlspecialchars($brand, ENT_QUOTES) . ' •••• ' . htmlspecialchars($last4, ENT_QUOTES) . '</td>
          </tr>
          <tr>
            <td style="padding:11px 0;color:#9aa9c0">Next renewal</td>
            <td style="padding:11px 0;color:#34d399;font-weight:700;text-align:right">' . $expFmt . '</td>
          </tr>
        </table>
      </div>
      <div style="text-align:center">
        <a href="http://127.0.0.1:8000" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#7856ed,#3cc5d9);color:#fff;font-weight:700;border-radius:12px;text-decoration:none;font-size:0.95rem">Go to dashboard →</a>
      </div>
    </div>
    <p style="text-align:center;color:#4a5568;font-size:0.75rem;margin-top:24px">
      LearnAdapt · Subscription receipt · Do not reply to this email.
    </p>
  </div>
</div>';

            $mail = (new Email())
                ->from('achrefzouamghi@gmail.com')
                ->to($userEmail)
                ->subject('✓ Payment confirmed – ' . $planLabel . ' plan ($' . $amountFmt . ')')
                ->html($html);

            $this->mailer->send($mail);
        } catch (\Throwable) {
            // Email failure must not break the payment confirmation
        }

        return $this->redirectToRoute('app_checkout_success', [
            'plan'  => $plan,
            'cycle' => $cycle,
        ]);
    }

    #[Route('/pricing/success/{plan}/{cycle}', name: 'app_checkout_success', methods: ['GET'])]
    public function success(Request $request, string $plan, string $cycle): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!isset(self::PLANS[$plan])) {
            return $this->redirectToRoute('app_pricing');
        }

        $conn      = $this->entityManager->getConnection();
        $expiresAt = $conn->fetchOne('SELECT plan_expires_at FROM users WHERE id = ?', [$user->getId()]);

        return $this->render('pricing/success.html.twig', [
            'user'      => $user,
            'planKey'   => $plan,
            'planData'  => self::PLANS[$plan],
            'cycle'     => $cycle,
            'expiresAt' => $expiresAt,
        ]);
    }

    #[Route('/pricing/cancel', name: 'app_plan_cancel', methods: ['POST'])]
    public function cancelPlan(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            "UPDATE users SET plan = 'free', plan_expires_at = NULL WHERE id = ?",
            [$user->getId()]
        );
        $conn->executeStatement(
            "UPDATE user_subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'",
            [$user->getId()]
        );

        $this->addFlash('success', 'Your subscription has been cancelled.');
        return $this->redirectToRoute('app_pricing');
    }
}
