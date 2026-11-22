<?php

namespace App\Repository;

use App\Entity\InstanceIntegrityCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceIntegrityCheck>
 */
class InstanceIntegrityCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceIntegrityCheck::class);
    }

    /**
     * Checks since the given time, newest first.
     *
     * @return list<InstanceIntegrityCheck>
     */
    public function findSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.checkedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('c.checkedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.checkedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
