<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Environment;
use App\Entity\Feature;
use App\Entity\FeatureValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method FeatureValue|null find($id, $lockMode = null, $lockVersion = null)
 * @method FeatureValue|null findOneBy(array $criteria, array $orderBy = null)
 * @method FeatureValue[]    findAll()
 * @method FeatureValue[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FeatureValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureValue::class);
    }

    public function findOneByFeatureAndEnvironment(Feature $feature, Environment $environment): ?FeatureValue
    {
        return $this->findOneBy([
            'feature' => $feature,
            'environment' => $environment,
        ]);
    }
}
