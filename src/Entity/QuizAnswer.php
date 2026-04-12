<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\QuizAnswerRepository;

#[ORM\Entity(repositoryClass: QuizAnswerRepository::class)]
#[ORM\Table(name: 'quiz_answers')]
class QuizAnswer
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

    #[ORM\OneToOne(targetEntity: QuizAttempt::class, inversedBy: 'quizAnswer')]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', unique: true)]
    private ?QuizAttempt $quizAttempt = null;

    public function getQuizAttempt(): ?QuizAttempt
    {
        return $this->quizAttempt;
    }

    public function setQuizAttempt(?QuizAttempt $quizAttempt): self
    {
        $this->quizAttempt = $quizAttempt;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: QuizQuestion::class, inversedBy: 'quizAnswer')]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', unique: true)]
    private ?QuizQuestion $quizQuestion = null;

    public function getQuizQuestion(): ?QuizQuestion
    {
        return $this->quizQuestion;
    }

    public function setQuizQuestion(?QuizQuestion $quizQuestion): self
    {
        $this->quizQuestion = $quizQuestion;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $chosen_option = null;

    public function getChosen_option(): ?string
    {
        return $this->chosen_option;
    }

    public function setChosen_option(?string $chosen_option): self
    {
        $this->chosen_option = $chosen_option;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $chosen_bool = null;

    public function isChosen_bool(): ?bool
    {
        return $this->chosen_bool;
    }

    public function setChosen_bool(?bool $chosen_bool): self
    {
        $this->chosen_bool = $chosen_bool;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $typed_answer = null;

    public function getTyped_answer(): ?string
    {
        return $this->typed_answer;
    }

    public function setTyped_answer(?string $typed_answer): self
    {
        $this->typed_answer = $typed_answer;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false)]
    private ?bool $is_correct = null;

    public function is_correct(): ?bool
    {
        return $this->is_correct;
    }

    public function setIs_correct(bool $is_correct): self
    {
        $this->is_correct = $is_correct;
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

    public function getChosenOption(): ?string
    {
        return $this->chosen_option;
    }

    public function setChosenOption(?string $chosen_option): static
    {
        $this->chosen_option = $chosen_option;

        return $this;
    }

    public function isChosenBool(): ?bool
    {
        return $this->chosen_bool;
    }

    public function setChosenBool(?bool $chosen_bool): static
    {
        $this->chosen_bool = $chosen_bool;

        return $this;
    }

    public function getTypedAnswer(): ?string
    {
        return $this->typed_answer;
    }

    public function setTypedAnswer(?string $typed_answer): static
    {
        $this->typed_answer = $typed_answer;

        return $this;
    }

    public function isCorrect(): ?bool
    {
        return $this->is_correct;
    }

    public function setIsCorrect(bool $is_correct): static
    {
        $this->is_correct = $is_correct;

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

}
