<?php

namespace App\Repository;

use App\Entity\DownloaderOptionPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloaderOptionPolicy>
 */
class DownloaderOptionPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloaderOptionPolicy::class);
    }

    /**
     * @return array<string, DownloaderOptionPolicy> by option key
     */
    public function findByDownloaderIndexedByOption(string $downloader): array
    {
        $policies = [];
        foreach ($this->findBy(['downloader' => $downloader]) as $policy) {
            $policies[(string) $policy->getOption()] = $policy;
        }

        return $policies;
    }
}
