<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ForumPostRepository;

#[ORM\Entity(repositoryClass: ForumPostRepository::class)]
#[ORM\Table(name: 'forum_posts')]
class ForumPost
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

    #[ORM\ManyToOne(targetEntity: ForumTopic::class, inversedBy: 'forumPosts')]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id')]
    private ?ForumTopic $forumTopic = null;

    public function getForumTopic(): ?ForumTopic
    {
        return $this->forumTopic;
    }

    public function setForumTopic(?ForumTopic $forumTopic): self
    {
        $this->forumTopic = $forumTopic;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'forumPosts')]
    #[ORM\JoinColumn(name: 'author_user_id', referencedColumnName: 'id')]
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

    #[ORM\Column(type: 'text', nullable: false)]
    private ?string $content = null;

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false)]
    private ?bool $is_expert_reply = null;

    public function is_expert_reply(): ?bool
    {
        return $this->is_expert_reply;
    }

    public function setIs_expert_reply(bool $is_expert_reply): self
    {
        $this->is_expert_reply = $is_expert_reply;
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

    public function isExpertReply(): ?bool
    {
        return $this->is_expert_reply;
    }

    public function setIsExpertReply(bool $is_expert_reply): static
    {
        $this->is_expert_reply = $is_expert_reply;

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
