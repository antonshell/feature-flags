<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeatureRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Table(name: 'feature')]
#[UniqueConstraint(name: 'feature_unique', columns: ['name', 'project_id'])]
#[ORM\Entity(repositoryClass: FeatureRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Feature
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

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'environments')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id')]
    private ?Project $project;

    #[ORM\OneToMany(mappedBy: 'feature', targetEntity: FeatureValue::class)]
    private Collection $values;

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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    /**
     * @return Collection|FeatureValue[]
     */
    public function getValues(): Collection
    {
        return $this->values;
    }
}
