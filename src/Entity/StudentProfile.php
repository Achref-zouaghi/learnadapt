<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\StudentProfileRepository;

#[ORM\Entity(repositoryClass: StudentProfileRepository::class)]
#[ORM\Table(name: 'student_profiles')]
class StudentProfile
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


 

  

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'studentProfiles')]
    #[ORM\JoinColumn(name: 'parent_user_id', referencedColumnName: 'id')]
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

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $birth_date = null;

    public function getBirth_date(): ?\DateTimeInterface
    {
        return $this->birth_date;
    }

    public function setBirth_date(?\DateTimeInterface $birth_date): self
    {
        $this->birth_date = $birth_date;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $age = null;

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): self
    {
        $this->age = $age;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $education_level = null;

    public function getEducation_level(): ?string
    {
        return $this->education_level;
    }

    public function setEducation_level(string $education_level): self
    {
        $this->education_level = $education_level;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $support_needed = null;

    public function getSupport_needed(): ?string
    {
        return $this->support_needed;
    }

    public function setSupport_needed(?string $support_needed): self
    {
        $this->support_needed = $support_needed;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $social_preference = null;

    public function getSocial_preference(): ?string
    {
        return $this->social_preference;
    }

    public function setSocial_preference(string $social_preference): self
    {
        $this->social_preference = $social_preference;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $communication_preference = null;

    public function getCommunication_preference(): ?string
    {
        return $this->communication_preference;
    }

    public function setCommunication_preference(string $communication_preference): self
    {
        $this->communication_preference = $communication_preference;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
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

    public function getBirthDate(): ?\DateTime
    {
        return $this->birth_date;
    }

    public function setBirthDate(?\DateTime $birth_date): static
    {
        $this->birth_date = $birth_date;

        return $this;
    }

    public function getEducationLevel(): ?string
    {
        return $this->education_level;
    }

    public function setEducationLevel(string $education_level): static
    {
        $this->education_level = $education_level;

        return $this;
    }

    public function getSupportNeeded(): ?string
    {
        return $this->support_needed;
    }

    public function setSupportNeeded(?string $support_needed): static
    {
        $this->support_needed = $support_needed;

        return $this;
    }

    public function getSocialPreference(): ?string
    {
        return $this->social_preference;
    }

    public function setSocialPreference(string $social_preference): static
    {
        $this->social_preference = $social_preference;

        return $this;
    }

    public function getCommunicationPreference(): ?string
    {
        return $this->communication_preference;
    }

    public function setCommunicationPreference(string $communication_preference): static
    {
        $this->communication_preference = $communication_preference;

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
