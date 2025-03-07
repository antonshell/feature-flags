<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Environment;
use App\Entity\Feature;
use App\Entity\FeatureValue;
use App\Entity\Project;
use Codeception\Lib\Di;
use Codeception\Lib\ModuleContainer;
use Codeception\Module\Doctrine2;
use Doctrine\ORM\EntityManager;
use Faker\Factory;
use Faker\Generator;

class DataFactory
{
    protected EntityManager $entityManager;
    private Doctrine2 $doctrine;
    protected Generator $faker;

    public function __construct(
        EntityManager $entityManager,
    ) {
        $this->entityManager = $entityManager;
        $this->faker = Factory::create();
        $this->initializeDoctrineModule();
    }

    public function createProject(array $data = []): Project
    {
        $data = $this->setDefaultValues($data, [
            'name' => 'demo',
            'description' => 'demo project',
            'owner' => 'antonshell',
            'readKey' => bin2hex(random_bytes(64)),
            'manageKey' => bin2hex(random_bytes(64)),
        ]);

        $data['readKey'] = password_hash($data['readKey'], PASSWORD_BCRYPT);
        $data['manageKey'] = password_hash($data['manageKey'], PASSWORD_BCRYPT);

        return $this->doctrine->grabEntityFromRepository(Project::class, [
            'id' => $this->doctrine->haveInRepository(Project::class, $data),
        ]);
    }

    public function createEnvironment(array $data = []): Environment
    {
        $data = $this->setDefaultValues($data, [
            'name' => 'prod',
            'description' => 'Production environment',
        ]);

        if (!array_key_exists('project', $data)) {
            $project = $this->createProject();
            $data['project'] = $project;
            $data['project_id'] = $project->getId();
        }

        return $this->doctrine->grabEntityFromRepository(Environment::class, [
            'id' => $this->doctrine->haveInRepository(Environment::class, $data),
        ]);
    }

    public function createFeature(array $data = []): Feature
    {
        $data = $this->setDefaultValues($data, [
            'name' => 'demo-feature',
            'description' => 'Demo feature',
        ]);

        if (!array_key_exists('project', $data)) {
            $project = $this->createProject();
            $data['project'] = $project;
            $data['project_id'] = $project->getId();
        }

        return $this->doctrine->grabEntityFromRepository(Feature::class, [
            'id' => $this->doctrine->haveInRepository(Feature::class, $data),
        ]);
    }

    public function createFeatureValue(array $data = []): FeatureValue
    {
        $data = $this->setDefaultValues($data, [
            'enabled' => true,
        ]);

        if (!array_key_exists('feature', $data)) {
            $feature = $this->createFeature();
            $data['feature'] = $feature;
            $data['feature_id'] = $feature->getId();
        }

        if (!array_key_exists('environment', $data)) {
            $environment = $this->createEnvironment();
            $data['environment'] = $environment;
            $data['environment_id'] = $environment->getId();
        }

        return $this->doctrine->grabEntityFromRepository(FeatureValue::class, [
            'id' => $this->doctrine->haveInRepository(FeatureValue::class, $data),
        ]);
    }

    protected function setDefaultValues(array $data, array $defaults): array
    {
        return array_merge($defaults, $data);
    }

    private function initializeDoctrineModule(): void
    {
        $di = new Di();
        $moduleContainer = new ModuleContainer($di, []);
        $this->doctrine = new Doctrine2($moduleContainer);

        $reflectionProperty = new \ReflectionProperty(Doctrine2::class, 'em');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->doctrine, $this->entityManager);
    }
}
