<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EnvironmentEnum;
use App\Repository\EnvironmentRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Table(name: 'environment')]
#[UniqueConstraint(name: 'environment_unique', columns: ['name', 'project_id'])]
#[ORM\Entity(repositoryClass: EnvironmentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Environment
{
    use TimestampTrait;

    public const ENV_PROD = 'prod';

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

    #[ORM\OneToMany(mappedBy: 'environment', targetEntity: FeatureValue::class)]
    private Collection $featuresValues;

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
    public function getFeaturesValues(): Collection
    {
        return $this->featuresValues;
    }

    public function isProdEnv(): bool
    {
        return EnvironmentEnum::PROD === $this->name;
    }
}
