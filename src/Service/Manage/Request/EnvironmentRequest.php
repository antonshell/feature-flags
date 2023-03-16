<?php

declare(strict_types=1);

namespace App\Service\Manage\Request;

use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

class EnvironmentRequest
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
