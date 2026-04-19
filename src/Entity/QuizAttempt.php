<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\QuizAttemptRepository;

#[ORM\Entity(repositoryClass: QuizAttemptRepository::class)]
#[ORM\Table(name: 'quiz_attempts')]
class QuizAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
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

    #[ORM\ManyToOne(targetEntity: DiagnosticQuizze::class, inversedBy: 'quizAttempts')]
    #[ORM\JoinColumn(name: 'quiz_id', referencedColumnName: 'id')]
    private ?DiagnosticQuizze $diagnosticQuizze = null;

    public function getDiagnosticQuizze(): ?DiagnosticQuizze
    {
        return $this->diagnosticQuizze;
    }

    public function setDiagnosticQuizze(?DiagnosticQuizze $diagnosticQuizze): self
    {
        $this->diagnosticQuizze = $diagnosticQuizze;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'quizAttempts')]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id')]
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
    private ?\DateTimeInterface $finished_at = null;

    public function getFinished_at(): ?\DateTimeInterface
    {
        return $this->finished_at;
    }

    public function setFinished_at(?\DateTimeInterface $finished_at): self
    {
        $this->finished_at = $finished_at;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $total_points = null;

    public function getTotal_points(): ?int
    {
        return $this->total_points;
    }

    public function setTotal_points(int $total_points): self
    {
        $this->total_points = $total_points;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $earned_points = null;

    public function getEarned_points(): ?int
    {
        return $this->earned_points;
    }

    public function setEarned_points(int $earned_points): self
    {
        $this->earned_points = $earned_points;
        return $this;
    }

    #[ORM\Column(type: 'decimal', nullable: false)]
    private ?string $score_percent = null;

    public function getScore_percent(): ?string
    {
        return $this->score_percent;
    }

    public function setScore_percent(string $score_percent): self
    {
        $this->score_percent = $score_percent;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $level_result = null;

    public function getLevel_result(): ?string
    {
        return $this->level_result;
    }

    public function setLevel_result(?string $level_result): self
    {
        $this->level_result = $level_result;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: QuizAnswer::class, mappedBy: 'quizAttempt')]
    private ?QuizAnswer $quizAnswer = null;

    public function getQuizAnswer(): ?QuizAnswer
    {
        return $this->quizAnswer;
    }

    public function setQuizAnswer(?QuizAnswer $quizAnswer): self
    {
        $this->quizAnswer = $quizAnswer;
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

    public function getFinishedAt(): ?\DateTime
    {
        return $this->finished_at;
    }

    public function setFinishedAt(?\DateTime $finished_at): static
    {
        $this->finished_at = $finished_at;

        return $this;
    }

    public function getTotalPoints(): ?int
    {
        return $this->total_points;
    }

    public function setTotalPoints(int $total_points): static
    {
        $this->total_points = $total_points;

        return $this;
    }

    public function getEarnedPoints(): ?int
    {
        return $this->earned_points;
    }

    public function setEarnedPoints(int $earned_points): static
    {
        $this->earned_points = $earned_points;

        return $this;
    }

    public function getScorePercent(): ?string
    {
        return $this->score_percent;
    }

    public function setScorePercent(string $score_percent): static
    {
        $this->score_percent = $score_percent;

        return $this;
    }

    public function getLevelResult(): ?string
    {
        return $this->level_result;
    }

    public function setLevelResult(?string $level_result): static
    {
        $this->level_result = $level_result;

        return $this;
    }

}
