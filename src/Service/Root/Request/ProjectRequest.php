<?php

declare(strict_types=1);

namespace App\Service\Root\Request;

use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

class ProjectRequest
{
    #[Serializer\SerializedName(name: 'name')]
    #[Serializer\Type(name: 'string')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Type(type: 'string')]
    private string $name;

    #[Serializer\SerializedName(name: 'description')]
    #[Serializer\Type(name: 'string')]
    #[Assert\Type(type: 'string')]
    private string $description;

    #[Serializer\SerializedName(name: 'owner')]
    #[Serializer\Type(name: 'string')]
    #[Assert\NotBlank]
    #[Assert\Type(type: 'string')]
    private string $owner;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }
}
