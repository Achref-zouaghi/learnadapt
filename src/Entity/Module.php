<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ModuleRepository;

#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\Table(name: 'modules')]
class Module
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
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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

    #[ORM\OneToMany(targetEntity: Course::class, mappedBy: 'module')]
    private Collection $courses;

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

    #[ORM\OneToMany(targetEntity: Exercise::class, mappedBy: 'module')]
    private Collection $exercises;

    public function __construct()
    {
        $this->courses = new ArrayCollection();
        $this->exercises = new ArrayCollection();
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

}
