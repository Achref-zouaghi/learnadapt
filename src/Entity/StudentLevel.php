<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\StudentLevelRepository;

#[ORM\Entity(repositoryClass: StudentLevelRepository::class)]
#[ORM\Table(name: 'student_levels')]
class StudentLevel
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

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'studentLevels')]
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $current_level = null;

    public function getCurrent_level(): ?string
    {
        return $this->current_level;
    }

    public function setCurrent_level(string $current_level): self
    {
        $this->current_level = $current_level;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $last_score_percent = null;

    public function getLast_score_percent(): ?string
    {
        return $this->last_score_percent;
    }

    public function setLast_score_percent(?string $last_score_percent): self
    {
        $this->last_score_percent = $last_score_percent;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $updated_at = null;

    public function getUpdated_at(): ?\DateTimeInterface
    {
        return $this->updated_at;
    }

    public function setUpdated_at(\DateTimeInterface $updated_at): self
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    public function getCurrentLevel(): ?string
    {
        return $this->current_level;
    }

    public function setCurrentLevel(string $current_level): static
    {
        $this->current_level = $current_level;

        return $this;
    }

    public function getLastScorePercent(): ?string
    {
        return $this->last_score_percent;
    }

    public function setLastScorePercent(?string $last_score_percent): static
    {
        $this->last_score_percent = $last_score_percent;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(\DateTime $updated_at): static
    {
        $this->updated_at = $updated_at;

        return $this;
    }

}
