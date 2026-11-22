<?php

namespace App\Tests\Double;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Repository\DownloadDirectoryRepository;
use App\Service\Storage\DownloadTargets;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds DownloadedFiles at absolute paths, in a download directory for the path's parent directory.
 */
final class DownloadLocations
{
    /**
     * An unsaved file, in an unsaved directory.
     */
    public static function file(string $absolutePath): DownloadedFile
    {
        return new DownloadedFile()->setLocation(new DownloadDirectory('Test', \dirname($absolutePath)), basename($absolutePath));
    }

    /**
     * A new (not yet persisted) file in the saved directory for the path's parent, which is created and flushed if needed.
     */
    public static function persistedDirectoryFile(EntityManagerInterface $entityManager, string $absolutePath): DownloadedFile
    {
        return new DownloadedFile()->setLocation(self::directory($entityManager, \dirname($absolutePath)), basename($absolutePath));
    }

    public static function directory(EntityManagerInterface $entityManager, string $path): DownloadDirectory
    {
        $directory = $entityManager->getRepository(DownloadDirectory::class)->findOneBy(['path' => $path]);
        if (null === $directory) {
            $directory = new DownloadDirectory('Test '.$path, $path);
            $entityManager->persist($directory);
            $entityManager->flush();
        }

        return $directory;
    }

    /**
     * Targets without a database: sites without a directory use a default directory in the temporary directory.
     */
    public static function targets(?DownloadDirectory $default = null): DownloadTargets
    {
        $default ??= new DownloadDirectory('Default', sys_get_temp_dir(), true);

        return new DownloadTargets(new class($default) extends DownloadDirectoryRepository {
            public function __construct(private readonly DownloadDirectory $default)
            {
            }

            public function findDefault(): DownloadDirectory
            {
                return $this->default;
            }
        });
    }
}
