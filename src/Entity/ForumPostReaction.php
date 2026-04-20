<?php

namespace App\Entity;

use App\Repository\ForumPostReactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForumPostReactionRepository::class)]
#[ORM\Table(name: 'forum_post_reactions')]
#[ORM\UniqueConstraint(name: 'uq_post_user', columns: ['post_id', 'user_id'])]
class ForumPostReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false)]
    private ?ForumPost $post = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $reaction_type = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $created_at = null;

    public function __construct()
    {
        $this->created_at = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): ?ForumPost { return $this->post; }
    public function setPost(?ForumPost $v): self { $this->post = $v; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getReactionType(): ?string { return $this->reaction_type; }
    public function setReactionType(string $v): self { $this->reaction_type = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $v): self { $this->created_at = $v; return $this; }
}
