<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Persistence\ObjectManager;

class EmptyFixture extends AbstractFixture
{
    public function load(ObjectManager $objectManager): void
    {
    }
}
