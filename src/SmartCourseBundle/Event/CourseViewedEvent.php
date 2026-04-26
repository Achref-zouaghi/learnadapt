<?php

namespace App\SmartCourseBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a user views a course detail page.
 *
 * Dispatch example:
 *   $dispatcher->dispatch(new CourseViewedEvent($userId, $courseId));
 */
final class CourseViewedEvent extends Event
{
    public const NAME = 'smart_course.course_viewed';

    public function __construct(
        private readonly int $userId,
        private readonly int $courseId,
        private readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }

    public function getUserId(): int   { return $this->userId; }
    public function getCourseId(): int { return $this->courseId; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
