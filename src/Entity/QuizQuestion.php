<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\QuizQuestionRepository;

#[ORM\Entity(repositoryClass: QuizQuestionRepository::class)]
#[ORM\Table(name: 'quiz_questions')]
class QuizQuestion
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

    #[ORM\ManyToOne(targetEntity: DiagnosticQuizze::class, inversedBy: 'quizQuestions')]
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $question_type = null;

    public function getQuestion_type(): ?string
    {
        return $this->question_type;
    }

    public function setQuestion_type(string $question_type): self
    {
        $this->question_type = $question_type;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    private ?string $prompt = null;

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $option_a = null;

    public function getOption_a(): ?string
    {
        return $this->option_a;
    }

    public function setOption_a(?string $option_a): self
    {
        $this->option_a = $option_a;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $option_b = null;

    public function getOption_b(): ?string
    {
        return $this->option_b;
    }

    public function setOption_b(?string $option_b): self
    {
        $this->option_b = $option_b;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $option_c = null;

    public function getOption_c(): ?string
    {
        return $this->option_c;
    }

    public function setOption_c(?string $option_c): self
    {
        $this->option_c = $option_c;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $option_d = null;

    public function getOption_d(): ?string
    {
        return $this->option_d;
    }

    public function setOption_d(?string $option_d): self
    {
        $this->option_d = $option_d;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $correct_option = null;

    public function getCorrect_option(): ?string
    {
        return $this->correct_option;
    }

    public function setCorrect_option(?string $correct_option): self
    {
        $this->correct_option = $correct_option;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $correct_bool = null;

    public function isCorrect_bool(): ?bool
    {
        return $this->correct_bool;
    }

    public function setCorrect_bool(?bool $correct_bool): self
    {
        $this->correct_bool = $correct_bool;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $correct_text = null;

    public function getCorrect_text(): ?string
    {
        return $this->correct_text;
    }

    public function setCorrect_text(?string $correct_text): self
    {
        $this->correct_text = $correct_text;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $points = null;

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function setPoints(int $points): self
    {
        $this->points = $points;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $difficulty = null;

    public function getDifficulty(): ?string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): self
    {
        $this->difficulty = $difficulty;
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

    #[ORM\OneToOne(targetEntity: QuizAnswer::class, mappedBy: 'quizQuestion')]
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

    public function getQuestionType(): ?string
    {
        return $this->question_type;
    }

    public function setQuestionType(string $question_type): static
    {
        $this->question_type = $question_type;

        return $this;
    }

    public function getOptionA(): ?string
    {
        return $this->option_a;
    }

    public function setOptionA(?string $option_a): static
    {
        $this->option_a = $option_a;

        return $this;
    }

    public function getOptionB(): ?string
    {
        return $this->option_b;
    }

    public function setOptionB(?string $option_b): static
    {
        $this->option_b = $option_b;

        return $this;
    }

    public function getOptionC(): ?string
    {
        return $this->option_c;
    }

    public function setOptionC(?string $option_c): static
    {
        $this->option_c = $option_c;

        return $this;
    }

    public function getOptionD(): ?string
    {
        return $this->option_d;
    }

    public function setOptionD(?string $option_d): static
    {
        $this->option_d = $option_d;

        return $this;
    }

    public function getCorrectOption(): ?string
    {
        return $this->correct_option;
    }

    public function setCorrectOption(?string $correct_option): static
    {
        $this->correct_option = $correct_option;

        return $this;
    }

    public function isCorrectBool(): ?bool
    {
        return $this->correct_bool;
    }

    public function setCorrectBool(?bool $correct_bool): static
    {
        $this->correct_bool = $correct_bool;

        return $this;
    }

    public function getCorrectText(): ?string
    {
        return $this->correct_text;
    }

    public function setCorrectText(?string $correct_text): static
    {
        $this->correct_text = $correct_text;

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
