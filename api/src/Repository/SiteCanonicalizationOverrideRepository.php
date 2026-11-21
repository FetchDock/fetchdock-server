<?php

namespace App\Repository;

use App\Entity\SiteCanonicalizationOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteCanonicalizationOverride>
 */
class SiteCanonicalizationOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteCanonicalizationOverride::class);
    }
}
