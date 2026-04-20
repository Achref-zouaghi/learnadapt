<?php

namespace App\Entity;

use App\Repository\CourseFileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CourseFileRepository::class)]
#[ORM\Table(name: 'course_files')]
class CourseFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Course $course = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'string', length: 500)]
    private ?string $file_path = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $original_name = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $file_size = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sort_order = 0;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $created_at = null;

    public function __construct()
    {
        $this->created_at = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getCourse(): ?Course { return $this->course; }
    public function setCourse(?Course $course): self { $this->course = $course; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getFilePath(): ?string { return $this->file_path; }
    public function setFilePath(string $v): self { $this->file_path = $v; return $this; }
    public function getOriginalName(): ?string { return $this->original_name; }
    public function setOriginalName(?string $v): self { $this->original_name = $v; return $this; }
    public function getFileSize(): ?int { return $this->file_size; }
    public function setFileSize(?int $v): self { $this->file_size = $v; return $this; }
    public function getSortOrder(): int { return $this->sort_order; }
    public function setSortOrder(int $v): self { $this->sort_order = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }
    public function setCreatedAt(\DateTimeInterface $v): self { $this->created_at = $v; return $this; }
}
