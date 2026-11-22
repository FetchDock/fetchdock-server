<?php

namespace App\Repository;

use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadedFile>
 */
class DownloadedFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadedFile::class);
    }

    /**
     * The file at a location, soft-deleted or not: a location holds at most one row.
     */
    public function findOneByLocation(DownloadDirectory $directory, string $relativePath): ?DownloadedFile
    {
        return new SoftDeleteFilter($this->getEntityManager())->disabled(fn (): ?DownloadedFile => $this->findOneBy([
            'directory' => $directory,
            'relativePath' => $relativePath,
        ]));
    }
}
