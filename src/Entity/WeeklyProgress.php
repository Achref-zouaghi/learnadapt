<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\WeeklyProgressRepository;

#[ORM\Entity(repositoryClass: WeeklyProgressRepository::class)]
#[ORM\Table(name: 'weekly_progress')]
class WeeklyProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'weeklyProgress')]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', unique: true)]
    private ?User $user = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $week_start_date = null;

    public function getWeek_start_date(): ?\DateTimeInterface
    {
        return $this->week_start_date;
    }

    public function setWeek_start_date(\DateTimeInterface $week_start_date): self
    {
        $this->week_start_date = $week_start_date;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $tasks_done = null;

    public function getTasks_done(): ?int
    {
        return $this->tasks_done;
    }

    public function setTasks_done(int $tasks_done): self
    {
        $this->tasks_done = $tasks_done;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $pomodoro_minutes = null;

    public function getPomodoro_minutes(): ?int
    {
        return $this->pomodoro_minutes;
    }

    public function setPomodoro_minutes(int $pomodoro_minutes): self
    {
        $this->pomodoro_minutes = $pomodoro_minutes;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $created_at = null;

    public function getCreated_at(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreated_at(\DateTimeInterface $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getWeekStartDate(): ?\DateTime
    {
        return $this->week_start_date;
    }

    public function setWeekStartDate(\DateTime $week_start_date): static
    {
        $this->week_start_date = $week_start_date;

        return $this;
    }

    public function getTasksDone(): ?int
    {
        return $this->tasks_done;
    }

    public function setTasksDone(int $tasks_done): static
    {
        $this->tasks_done = $tasks_done;

        return $this;
    }

    public function getPomodoroMinutes(): ?int
    {
        return $this->pomodoro_minutes;
    }

    public function setPomodoroMinutes(int $pomodoro_minutes): static
    {
        $this->pomodoro_minutes = $pomodoro_minutes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $created_at): static
    {
        $this->created_at = $created_at;

        return $this;
    }

}
