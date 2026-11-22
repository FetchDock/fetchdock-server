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

    /**
     * Files per directory, soft-deleted ones included (they keep their location).
     *
     * @return array<int, int> by directory id
     */
    public function countFiles(): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT directory_id, COUNT(*) FROM downloaded_file GROUP BY directory_id'
        ));
    }

    /**
     * Sites with their own directory, per directory.
     *
     * @return array<int, int> by directory id
     */
    public function countSites(): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT download_directory_id, COUNT(*) FROM site WHERE download_directory_id IS NOT NULL GROUP BY download_directory_id'
        ));
    }

    /**
     * Makes the directory the default one, in its own transaction (the unique index allows one default at a time).
     */
    public function makeDefault(DownloadDirectory $directory): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($directory): void {
            $previousDefaults = $this->findBy(['isDefault' => true]);
            $this->getEntityManager()->getConnection()->executeStatement('UPDATE download_directory SET is_default = false WHERE is_default AND id <> :id', ['id' => $directory->getId()]);
            foreach ($previousDefaults as $previous) {
                if ($previous !== $directory) {
                    $this->getEntityManager()->refresh($previous);
                }
            }
            $directory->setIsDefault(true);
            $this->getEntityManager()->flush();
        });
    }
}
