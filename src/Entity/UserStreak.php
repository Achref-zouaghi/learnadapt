<?php

namespace App\Entity;

use App\Repository\UserStreakRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserStreakRepository::class)]
#[ORM\Table(name: 'user_streaks')]
class UserStreak
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', unique: true, nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $current_streak = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $longest_streak = 0;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $last_activity_date = null;

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getCurrentStreak(): int { return $this->current_streak; }
    public function setCurrentStreak(int $v): self { $this->current_streak = $v; return $this; }
    public function getLongestStreak(): int { return $this->longest_streak; }
    public function setLongestStreak(int $v): self { $this->longest_streak = $v; return $this; }
    public function getLastActivityDate(): ?\DateTimeInterface { return $this->last_activity_date; }
    public function setLastActivityDate(?\DateTimeInterface $v): self { $this->last_activity_date = $v; return $this; }
}
