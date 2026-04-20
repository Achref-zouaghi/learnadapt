<?php

namespace App\Entity;

use App\Repository\FeedbackReactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedbackReactionRepository::class)]
#[ORM\Table(name: 'feedback_reactions')]
#[ORM\UniqueConstraint(name: 'uq_feedback_user', columns: ['feedback_id', 'user_id'])]
class FeedbackReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AppFeedback::class)]
    #[ORM\JoinColumn(name: 'feedback_id', referencedColumnName: 'id', nullable: false)]
    private ?AppFeedback $feedback = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $type = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $created_at = null;

    public function __construct()
    {
        $this->created_at = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getFeedback(): ?AppFeedback { return $this->feedback; }
    public function setFeedback(?AppFeedback $v): self { $this->feedback = $v; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $v): self { $this->created_at = $v; return $this; }
}
