<?php

namespace App\Repository;

use App\Entity\DownloadDirectory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadDirectory>
 */
class DownloadDirectoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadDirectory::class);
    }

    public function findDefault(): DownloadDirectory
    {
        return $this->findOneBy(['isDefault' => true])
            ?? throw new \LogicException('No default download directory is configured.');
    }
}
