<?php

namespace App\Entity;

use App\Repository\CourseProgressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourseProgressRepository::class)]
#[ORM\Table(name: 'course_progress')]
#[ORM\UniqueConstraint(name: 'uq_progress', columns: ['user_id', 'course_id'])]
class CourseProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Course $course = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $progress_percent = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $xp_earned = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completed_at = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $last_accessed = null;

    public function __construct()
    {
        $this->last_accessed = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getCourse(): ?Course { return $this->course; }
    public function setCourse(?Course $course): self { $this->course = $course; return $this; }
    public function getProgressPercent(): int { return $this->progress_percent; }
    public function setProgressPercent(int $v): self { $this->progress_percent = $v; return $this; }
    public function getXpEarned(): int { return $this->xp_earned; }
    public function setXpEarned(int $v): self { $this->xp_earned = $v; return $this; }
    public function getCompletedAt(): ?\DateTimeInterface { return $this->completed_at; }
    public function setCompletedAt(?\DateTimeInterface $v): self { $this->completed_at = $v; return $this; }
    public function getLastAccessed(): ?\DateTimeInterface { return $this->last_accessed; }
    public function setLastAccessed(\DateTimeInterface $v): self { $this->last_accessed = $v; return $this; }
}
