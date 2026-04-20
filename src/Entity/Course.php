<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CourseRepository;

#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'courses')]
class Course
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

    #[ORM\ManyToOne(targetEntity: Module::class, inversedBy: 'courses')]
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

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'courses')]
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

    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'course')]
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

    #[ORM\ManyToMany(targetEntity: Exercise::class, inversedBy: 'courses')]
    #[ORM\JoinTable(
        name: 'course_exercises',
        joinColumns: [
            new ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'exercise_id', referencedColumnName: 'id')
        ]
    )]
    private Collection $exercises;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
        $this->exercises = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->prerequisites = new ArrayCollection();
    }

    /**
     * @return Collection<int, Exercise>
     */
    public function getExercises(): Collection
    {
        if (!$this->exercises instanceof Collection) {
            $this->exercises = new ArrayCollection();
        }
        return $this->exercises;
    }

    public function addExercise(Exercise $exercise): self
    {
        if (!$this->getExercises()->contains($exercise)) {
            $this->getExercises()->add($exercise);
        }
        return $this;
    }

    public function removeExercise(Exercise $exercise): self
    {
        $this->getExercises()->removeElement($exercise);
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

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $pdf_path = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $video_url = null;

    #[ORM\ManyToMany(targetEntity: CourseTag::class)]
    #[ORM\JoinTable(
        name: 'course_tag_map',
        joinColumns: [new ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $tags;

    #[ORM\ManyToMany(targetEntity: Course::class)]
    #[ORM\JoinTable(
        name: 'course_prerequisites',
        joinColumns: [new ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'prerequisite_course_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $prerequisites;

    public function getPdfPath(): ?string
    {
        return $this->pdf_path;
    }

    public function setPdfPath(?string $pdf_path): static
    {
        $this->pdf_path = $pdf_path;

        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->video_url;
    }

    public function setVideoUrl(?string $video_url): static
    {
        $this->video_url = $video_url;
        return $this;
    }

    /** @return Collection<int, CourseTag> */
    public function getTags(): Collection
    {
        if (!$this->tags instanceof Collection) {
            $this->tags = new ArrayCollection();
        }
        return $this->tags;
    }

    public function addTag(CourseTag $tag): self
    {
        if (!$this->getTags()->contains($tag)) {
            $this->getTags()->add($tag);
        }
        return $this;
    }

    public function removeTag(CourseTag $tag): self
    {
        $this->getTags()->removeElement($tag);
        return $this;
    }

    /** @return Collection<int, Course> */
    public function getPrerequisites(): Collection
    {
        if (!$this->prerequisites instanceof Collection) {
            $this->prerequisites = new ArrayCollection();
        }
        return $this->prerequisites;
    }

    public function addPrerequisite(Course $course): self
    {
        if (!$this->getPrerequisites()->contains($course)) {
            $this->getPrerequisites()->add($course);
        }
        return $this;
    }

    public function removePrerequisite(Course $course): self
    {
        $this->getPrerequisites()->removeElement($course);
        return $this;
    }
}
