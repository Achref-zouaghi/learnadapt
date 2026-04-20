<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ExerciseRepository;

#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Table(name: 'exercises')]
class Exercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
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

    #[ORM\ManyToOne(targetEntity: Module::class, inversedBy: 'exercises')]
    #[ORM\JoinColumn(name: 'module_id', referencedColumnName: 'id')]
    private ?Module $module = null;

    public function getModule(): ?Module
    {
        return $this->module;
    }

    public function setModule(?Module $module): self
    {
        $this->module = $module;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'exercises')]
    #[ORM\JoinColumn(name: 'teacher_user_id', referencedColumnName: 'id')]
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $level = null;

    public function getLevel(): ?string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $pdf_path = null;

    public function getPdf_path(): ?string
    {
        return $this->pdf_path;
    }

    public function setPdf_path(?string $pdf_path): self
    {
        $this->pdf_path = $pdf_path;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $pdf_original_name = null;

    public function getPdf_original_name(): ?string
    {
        return $this->pdf_original_name;
    }

    public function setPdf_original_name(?string $pdf_original_name): self
    {
        $this->pdf_original_name = $pdf_original_name;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pdf_size_bytes = null;

    public function getPdf_size_bytes(): ?int
    {
        return $this->pdf_size_bytes;
    }

    public function setPdf_size_bytes(?int $pdf_size_bytes): self
    {
        $this->pdf_size_bytes = $pdf_size_bytes;
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

    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'exercise')]
    private Collection $tasks;

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        if (!$this->tasks instanceof Collection) {
            $this->tasks = new ArrayCollection();
        }
        return $this->tasks;
    }

    public function addTask(Task $task): self
    {
        if (!$this->getTasks()->contains($task)) {
            $this->getTasks()->add($task);
        }
        return $this;
    }

    public function removeTask(Task $task): self
    {
        $this->getTasks()->removeElement($task);
        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Course::class, mappedBy: 'exercises')]
    private Collection $courses;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
        $this->courses = new ArrayCollection();
    }

    /**
     * @return Collection<int, Course>
     */
    public function getCourses(): Collection
    {
        if (!$this->courses instanceof Collection) {
            $this->courses = new ArrayCollection();
        }
        return $this->courses;
    }

    public function addCourse(Course $course): self
    {
        if (!$this->getCourses()->contains($course)) {
            $this->getCourses()->add($course);
        }
        return $this;
    }

    public function removeCourse(Course $course): self
    {
        $this->getCourses()->removeElement($course);
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdf_path;
    }

    public function setPdfPath(?string $pdf_path): static
    {
        $this->pdf_path = $pdf_path;

        return $this;
    }

    public function getPdfOriginalName(): ?string
    {
        return $this->pdf_original_name;
    }

    public function setPdfOriginalName(?string $pdf_original_name): static
    {
        $this->pdf_original_name = $pdf_original_name;

        return $this;
    }

    public function getPdfSizeBytes(): ?int
    {
        return $this->pdf_size_bytes;
    }

    public function setPdfSizeBytes(?int $pdf_size_bytes): static
    {
        $this->pdf_size_bytes = $pdf_size_bytes;

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
