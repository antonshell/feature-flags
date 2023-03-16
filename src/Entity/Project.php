<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Table(name: 'project')]
#[UniqueConstraint(name: 'project_unique', columns: ['name', 'owner'])]
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Project
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', length: 255)]
    private string $owner;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $readKey;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $manageKey;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Environment::class)]
    private Collection $environments;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Feature::class)]
    private Collection $features;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(string $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getReadKey(): ?string
    {
        return $this->readKey;
    }

    public function setReadKey(string $readKey): self
    {
        $this->readKey = $readKey;

        return $this;
    }

    public function getManageKey(): ?string
    {
        return $this->manageKey;
    }

    public function setManageKey(string $manageKey): self
    {
        $this->manageKey = $manageKey;

        return $this;
    }

    /**
     * @return Collection|Environment[]
     */
    public function getEnvironments(): Collection
    {
        return $this->environments;
    }

    /**
     * @return Collection|Feature[]
     */
    public function getFeatures(): Collection
    {
        return $this->features;
    }
}
