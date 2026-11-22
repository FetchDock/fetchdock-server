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
}
