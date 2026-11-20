<?php

namespace App\Repository;

use App\Entity\SiteExtractor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteExtractor>
 */
class SiteExtractorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteExtractor::class);
    }

    public function findOneByDownloaderAndKey(string $downloader, string $extractorKey): ?SiteExtractor
    {
        return $this->findOneBy(['downloader' => $downloader, 'extractorKey' => $extractorKey]);
    }
}
