<?php

namespace App\SmartCourseBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a user enrols in a course (first progress record created).
 *
 * Dispatch example:
 *   $dispatcher->dispatch(new CourseEnrolledEvent($userId, $courseId, $courseTitle));
 */
final class CourseEnrolledEvent extends Event
{
    public const NAME = 'smart_course.course_enrolled';

    public function __construct(
        private readonly int $userId,
        private readonly int $courseId,
        private readonly string $courseTitle,
        private readonly string $userEmail = '',
        private readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }

    public function getUserId(): int      { return $this->userId; }
    public function getCourseId(): int    { return $this->courseId; }
    public function getCourseTitle(): string { return $this->courseTitle; }
    public function getUserEmail(): string   { return $this->userEmail; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
