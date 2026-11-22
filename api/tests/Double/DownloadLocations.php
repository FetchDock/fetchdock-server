<?php

namespace App\Tests\Double;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
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
}
