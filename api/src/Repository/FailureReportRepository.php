<?php

namespace App\Repository;

use App\Entity\FailureReport;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\FailureReportKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FailureReport>
 */
class FailureReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FailureReport::class);
    }

    /**
     * The owner's latest report of the same kind for the same media (canonical key) since the given time.
     */
    public function findRecentDuplicate(OidcSubjectIdentifier $owner, string $canonicalKey, FailureReportKind $kind, \DateTimeImmutable $since): ?FailureReport
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.owner = :owner')
            ->andWhere('r.canonicalKey = :canonicalKey')
            ->andWhere('r.kind = :kind')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('owner', $owner)
            ->setParameter('canonicalKey', $canonicalKey)
            ->setParameter('kind', $kind)
            ->setParameter('since', $since)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
