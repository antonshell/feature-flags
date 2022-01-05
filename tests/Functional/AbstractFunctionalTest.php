<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\ReferenceRepository;
use Doctrine\DBAL\Driver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractFunctionalTest extends WebTestCase
{
    protected KernelBrowser $client;
    private ReferenceRepository $referenceRepository;
    private EntityManagerInterface $entityManager;
    private Loader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->loader = new Loader();

        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->beginTransaction();
        }

        $this->referenceRepository = $this->loadFixtures($this->getFixtures())->getReferenceRepository();
    }

    abstract protected function getFixtures(): array;

    protected function getReference(string $name): object
    {
        return $this->referenceRepository->getReference($name);
    }

    private function loadFixtures(array $fixtures): ORMExecutor
    {
        foreach ($fixtures as $fixture) {
            $this->loadFixture($fixture);
        }

        $connection = $this->entityManager->getConnection();
        if ($connection->getDriver() instanceof Driver) {
            $connection->executeStatement('SET foreign_key_checks = 0;');
        }

        $purger = new ORMPurger();
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);
        $executor = new ORMExecutor($this->entityManager, $purger);
        $executor->purge();

        foreach ($this->loader->getFixtures() as $fixture) {
            $executor->load($this->entityManager, $fixture);
        }

        if ($connection->getDriver() instanceof Driver) {
            $this->entityManager->getConnection()->executeStatement('SET foreign_key_checks = 1;');
        }

        return $executor;
    }

    private function loadFixture(string $fixtureName): void
    {
        $fixture = new $fixtureName();
        if (!$this->loader->hasFixture($fixture)) {
            $this->loader->addFixture($fixture);
        }

        if ($fixture instanceof DependentFixtureInterface) {
            foreach ($fixture->getDependencies() as $dependency) {
                $this->loadFixture($dependency);
            }
        }
    }
}
