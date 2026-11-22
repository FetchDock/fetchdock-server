<?php

namespace App\Repository;

use App\Entity\PrivacyPolicyRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivacyPolicyRevision>
 */
class PrivacyPolicyRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivacyPolicyRevision::class);
    }

    public function findLatest(): ?PrivacyPolicyRevision
    {
        return $this->findOneBy([], ['number' => 'DESC']);
    }

    public function findOneByNumber(int $number): ?PrivacyPolicyRevision
    {
        return $this->findOneBy(['number' => $number]);
    }

    /**
     * @return list<PrivacyPolicyRevision> newest first
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['number' => 'DESC']);
    }

    /**
     * Metadata of all revisions without loading their markdown/html bodies, for the public revision list.
     *
     * @return list<array{number: int, createdAt: \DateTimeImmutable, appVersion: string, textVersion: string}>
     */
    public function findSummariesNewestFirst(): array
    {
        /** @var list<array{number: int, createdAt: \DateTimeImmutable, appVersion: string, textVersion: string}> */
        return $this->createQueryBuilder('r')
            ->select('r.number, r.createdAt, r.appVersion, r.textVersion')
            ->orderBy('r.number', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }
}
