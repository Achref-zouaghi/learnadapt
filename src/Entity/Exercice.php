<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ExerciceRepository;

#[ORM\Entity(repositoryClass: ExerciceRepository::class)]
#[ORM\Table(name: 'exercice')]
class Exercice
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
    private ?string $titre = null;

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $niveau = null;

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(string $niveau): self
    {
        $this->niveau = $niveau;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $module = null;

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function setModule(string $module): self
    {
        $this->module = $module;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $fichier_exercice = null;

    public function getFichier_exercice(): ?string
    {
        return $this->fichier_exercice;
    }

    public function setFichier_exercice(?string $fichier_exercice): self
    {
        $this->fichier_exercice = $fichier_exercice;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $fichier_correction = null;

    public function getFichier_correction(): ?string
    {
        return $this->fichier_correction;
    }

    public function setFichier_correction(?string $fichier_correction): self
    {
        $this->fichier_correction = $fichier_correction;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $date_creation = null;

    public function getDate_creation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDate_creation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    public function getFichierExercice(): ?string
    {
        return $this->fichier_exercice;
    }

    public function setFichierExercice(?string $fichier_exercice): static
    {
        $this->fichier_exercice = $fichier_exercice;

        return $this;
    }

    public function getFichierCorrection(): ?string
    {
        return $this->fichier_correction;
    }

    public function setFichierCorrection(?string $fichier_correction): static
    {
        $this->fichier_correction = $fichier_correction;

        return $this;
    }

    public function getDateCreation(): ?\DateTime
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTime $date_creation): static
    {
        $this->date_creation = $date_creation;

        return $this;
    }

}
