<?php

namespace App\Entity;

use App\Repository\UserStrikeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserStrikeRepository::class)]
#[ORM\Table(name: 'user_strikes')]
class UserStrike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $reason = null;

    #[ORM\Column(type: 'text')]
    private ?string $detected_words = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $created_at = null;

    public function __construct()
    {
        $this->created_at = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(string $v): self { $this->reason = $v; return $this; }
    public function getDetectedWords(): ?string { return $this->detected_words; }
    public function setDetectedWords(string $v): self { $this->detected_words = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $v): self { $this->created_at = $v; return $this; }
}
