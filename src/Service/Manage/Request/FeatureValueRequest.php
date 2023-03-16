<?php

declare(strict_types=1);

namespace App\Service\Manage\Request;

use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

class FeatureValueRequest
{
    #[Serializer\SerializedName(name: 'environment')]
    #[Serializer\Type(name: 'string')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Type(type: 'string')]
    private string $environment;

    #[Serializer\SerializedName(name: 'enabled')]
    #[Serializer\Type(name: 'boolean')]
    #[Assert\NotBlank]
    #[Assert\Type(type: 'boolean')]
    private bool $enabled;

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }
}
