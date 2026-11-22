<?php

namespace App\Repository;

use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Entity\FileMoveItem;
use App\Entity\Site;
use App\Enum\FileMoveItemStatus;
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

    /**
     * Live files of the site's jobs, and how many of them are stored in another directory than the given one.
     *
     * @return array{total: int, elsewhere: int}
     */
    public function countForSite(Site $site, DownloadDirectory $directory): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(DISTINCT f.id) AS total, COUNT(DISTINCT f.id) FILTER (WHERE f.directory_id <> :directory) AS elsewhere
            FROM downloaded_file f
            JOIN downloaded_file_download_job l ON l.downloaded_file_id = f.id
            JOIN download_job j ON j.id = l.download_job_id
            WHERE j.site_id = :site AND f.deleted_at IS NULL',
            ['site' => $site->getId(), 'directory' => $directory->getId()],
        );

        return ['total' => (int) ($row['total'] ?? 0), 'elsewhere' => (int) ($row['elsewhere'] ?? 0)];
    }

    /**
     * The file that a completed move took away from this location (tool output of old jobs points there).
     */
    public function findMovedFrom(DownloadDirectory $directory, string $relativePath): ?DownloadedFile
    {
        return new SoftDeleteFilter($this->getEntityManager())->disabled(fn (): ?DownloadedFile => $this->createQueryBuilder('f')
            ->join(FileMoveItem::class, 'i', 'WITH', 'i.file = f')
            ->andWhere('i.sourceDirectory = :directory AND i.sourceRelativePath = :path AND i.status = :moved')
            ->setParameter('directory', $directory)
            ->setParameter('path', $relativePath)
            ->setParameter('moved', FileMoveItemStatus::MOVED)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult());
    }
}
