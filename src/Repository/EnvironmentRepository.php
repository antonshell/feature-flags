<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Environment;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Environment|null find($id, $lockMode = null, $lockVersion = null)
 * @method Environment|null findOneBy(array $criteria, array $orderBy = null)
 * @method Environment[]    findAll()
 * @method Environment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EnvironmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Environment::class);
    }

    /**
     * @return Environment[]
     */
    public function findAllByProject(Project $project): array
    {
        return $this->findBy(['project' => $project]);
    }
}
