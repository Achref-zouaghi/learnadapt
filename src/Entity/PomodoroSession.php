<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\PomodoroSessionRepository;

#[ORM\Entity(repositoryClass: PomodoroSessionRepository::class)]
#[ORM\Table(name: 'pomodoro_sessions')]
class PomodoroSession
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

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'pomodoroSessions')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id')]
    private ?Task $task = null;

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): self
    {
        $this->task = $task;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $work_minutes = null;

    public function getWork_minutes(): ?int
    {
        return $this->work_minutes;
    }

    public function setWork_minutes(int $work_minutes): self
    {
        $this->work_minutes = $work_minutes;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $break_minutes = null;

    public function getBreak_minutes(): ?int
    {
        return $this->break_minutes;
    }

    public function setBreak_minutes(int $break_minutes): self
    {
        $this->break_minutes = $break_minutes;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $cycles = null;

    public function getCycles(): ?int
    {
        return $this->cycles;
    }

    public function setCycles(int $cycles): self
    {
        $this->cycles = $cycles;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $started_at = null;

    public function getStarted_at(): ?\DateTimeInterface
    {
        return $this->started_at;
    }

    public function setStarted_at(\DateTimeInterface $started_at): self
    {
        $this->started_at = $started_at;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $ended_at = null;

    public function getEnded_at(): ?\DateTimeInterface
    {
        return $this->ended_at;
    }

    public function setEnded_at(?\DateTimeInterface $ended_at): self
    {
        $this->ended_at = $ended_at;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false)]
    private ?bool $completed = null;

    public function isCompleted(): ?bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): self
    {
        $this->completed = $completed;
        return $this;
    }

    public function getWorkMinutes(): ?int
    {
        return $this->work_minutes;
    }

    public function setWorkMinutes(int $work_minutes): static
    {
        $this->work_minutes = $work_minutes;

        return $this;
    }

    public function getBreakMinutes(): ?int
    {
        return $this->break_minutes;
    }

    public function setBreakMinutes(int $break_minutes): static
    {
        $this->break_minutes = $break_minutes;

        return $this;
    }

    public function getStartedAt(): ?\DateTime
    {
        return $this->started_at;
    }

    public function setStartedAt(\DateTime $started_at): static
    {
        $this->started_at = $started_at;

        return $this;
    }

    public function getEndedAt(): ?\DateTime
    {
        return $this->ended_at;
    }

    public function setEndedAt(?\DateTime $ended_at): static
    {
        $this->ended_at = $ended_at;

        return $this;
    }

}
