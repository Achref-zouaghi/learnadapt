<?php

namespace App\SmartCourseBundle\EventSubscriber;

use App\SmartCourseBundle\Event\CourseEnrolledEvent;
use App\SmartCourseBundle\Event\CourseViewedEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * CourseActivitySubscriber
 *
 * Listens to SmartCourseBundle events and:
 *   1. Records activity into user_activity for the recommendation engine
 *   2. Sends welcome email on first enrolment (if notifications.email = true)
 */
class CourseActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $conn,
        private readonly MailerInterface $mailer,
        private readonly bool $emailEnabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CourseViewedEvent::NAME   => 'onCourseViewed',
            CourseEnrolledEvent::NAME => 'onCourseEnrolled',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function onCourseViewed(CourseViewedEvent $event): void
    {
        $this->recordActivity($event->getUserId(), 'view', $event->getCourseId());
    }

    public function onCourseEnrolled(CourseEnrolledEvent $event): void
    {
        $this->recordActivity($event->getUserId(), 'enroll', $event->getCourseId());

        if ($this->emailEnabled && $event->getUserEmail()) {
            $this->sendEnrolmentEmail($event);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function recordActivity(int $userId, string $type, ?int $courseId): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO user_activity (user_id, activity_type, course_id, created_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE created_at = NOW()',
                [$userId, $type, $courseId]
            );
        } catch (\Throwable) {
            // Non-critical — never break the main request
        }
    }

    private function sendEnrolmentEmail(CourseEnrolledEvent $event): void
    {
        try {
            $email = (new Email())
                ->to($event->getUserEmail())
                ->subject('🎓 You enrolled in: ' . $event->getCourseTitle())
                ->html(sprintf(
                    '<p>Hi! You have successfully enrolled in <strong>%s</strong>. Start learning now!</p>',
                    htmlspecialchars($event->getCourseTitle())
                ));

            $this->mailer->send($email);
        } catch (\Throwable) {
            // Email failure must never crash the request
        }
    }
}
