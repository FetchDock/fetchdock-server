<?php

namespace App\Repository;

use App\Resource\Stats\UserDownloadStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDownloadStats>
 */
class UserDownloadStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDownloadStats::class);
    }
}
