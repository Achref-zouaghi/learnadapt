<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\DiagnosticQuizzeRepository;

#[ORM\Entity(repositoryClass: DiagnosticQuizzeRepository::class)]
#[ORM\Table(name: 'diagnostic_quizzes')]
class DiagnosticQuizze
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $title = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false)]
    private ?bool $is_active = null;

    public function is_active(): ?bool
    {
        return $this->is_active;
    }

    public function setIs_active(bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'diagnosticQuizzes')]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id')]
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

    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'diagnosticQuizze')]
    private Collection $quizAttempts;

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttempts(): Collection
    {
        if (!$this->quizAttempts instanceof Collection) {
            $this->quizAttempts = new ArrayCollection();
        }
        return $this->quizAttempts;
    }

    public function addQuizAttempt(QuizAttempt $quizAttempt): self
    {
        if (!$this->getQuizAttempts()->contains($quizAttempt)) {
            $this->getQuizAttempts()->add($quizAttempt);
        }
        return $this;
    }

    public function removeQuizAttempt(QuizAttempt $quizAttempt): self
    {
        $this->getQuizAttempts()->removeElement($quizAttempt);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: QuizQuestion::class, mappedBy: 'diagnosticQuizze')]
    private Collection $quizQuestions;

    public function __construct()
    {
        $this->quizAttempts = new ArrayCollection();
        $this->quizQuestions = new ArrayCollection();
    }

    /**
     * @return Collection<int, QuizQuestion>
     */
    public function getQuizQuestions(): Collection
    {
        if (!$this->quizQuestions instanceof Collection) {
            $this->quizQuestions = new ArrayCollection();
        }
        return $this->quizQuestions;
    }

    public function addQuizQuestion(QuizQuestion $quizQuestion): self
    {
        if (!$this->getQuizQuestions()->contains($quizQuestion)) {
            $this->getQuizQuestions()->add($quizQuestion);
        }
        return $this;
    }

    public function removeQuizQuestion(QuizQuestion $quizQuestion): self
    {
        $this->getQuizQuestions()->removeElement($quizQuestion);
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): static
    {
        $this->is_active = $is_active;

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
