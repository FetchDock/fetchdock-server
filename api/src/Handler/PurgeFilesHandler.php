<?php

namespace App\Handler;

use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Message\PurgeFilesMessage;
use App\Repository\DownloadedFileRepository;
use App\Service\Storage\DownloadLocationResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes files no download job uses anymore: the DownloadedFile row, the file on disk, the yt-dlp .info.json next to
 * it (when no other file shares it) and the directories that became empty, up to the download directory (server#129).
 */
#[AsMessageHandler]
final readonly class PurgeFilesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DownloadedFileRepository $downloadedFileRepository,
        private DownloadLocationResolver $locationResolver,
        private SoftDeleteFilter $softDeleteFilter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeFilesMessage $message): void
    {
        $purged = 0;
        $kept = 0;

        foreach (array_unique($message->fileIds) as $fileId) {
            $file = $this->softDeleteFilter->disabled(fn (): ?DownloadedFile => $this->entityManager->find(DownloadedFile::class, $fileId));
            if (null !== $file) {
                $this->purgeFile($file) ? ++$purged : ++$kept;
            }
        }

        foreach (array_unique($message->paths) as $path) {
            $this->purgePath($path, $message->writtenSince) ? ++$purged : ++$kept;
        }

        // Rows were deleted with SQL, so the entity manager may hold stale entities
        $this->entityManager->clear();

        $this->logger->info('Purged files no download job uses anymore', ['purged' => $purged, 'kept' => $kept]);
    }

    private function purgeFile(DownloadedFile $file): bool
    {
        // Atomic check: a job that links the file in the meantime keeps it
        $deleted = $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM downloaded_file f WHERE f.id = :id AND NOT EXISTS (SELECT 1 FROM downloaded_file_download_job l WHERE l.downloaded_file_id = f.id)',
            ['id' => $file->getId()],
        );
        if (0 === $deleted) {
            return false;
        }

        $this->removeFromDisk($file->getDirectory(), $file->getRelativePath());

        return true;
    }

    private function purgePath(string $path, ?int $writtenSince): bool
    {
        $location = $this->locationResolver->fromAbsolute($path);
        if (null === $location) {
            return false;
        }

        $file = $this->downloadedFileRepository->findOneByLocation($location->directory, $location->relativePath);
        if (null !== $file) {
            return $this->purgeFile($file);
        }

        // Not tracked: only a file the deleted job wrote, never one that was there before it
        $absolutePath = $location->directory->resolve($location->relativePath);
        clearstatcache(true, $absolutePath);
        if (null === $writtenSince || !is_file($absolutePath) || filectime($absolutePath) < $writtenSince) {
            return false;
        }

        return $this->removeFromDisk($location->directory, $location->relativePath);
    }

    private function removeFromDisk(DownloadDirectory $directory, string $relativePath): bool
    {
        $absolutePath = $directory->resolve($relativePath);
        if (is_file($absolutePath) && !@unlink($absolutePath)) {
            $this->logger->warning('Unable to delete a file no download job uses anymore', ['path' => $absolutePath]);

            return false;
        }

        $this->removeInfoJson($directory, $relativePath);
        $this->removeEmptyParents(\dirname($absolutePath), $directory->getPath());

        return true;
    }

    /**
     * yt-dlp writes "<name>.info.json" next to the media file "<name>.<ext>". It goes once no other file uses that name.
     */
    private function removeInfoJson(DownloadDirectory $directory, string $relativePath): void
    {
        $stem = preg_replace('/\.[^.\/]+$/', '', $relativePath);
        $infoJson = $directory->resolve($stem.'.info.json');
        if (!is_file($infoJson)) {
            return;
        }

        $shared = $this->entityManager->getConnection()->fetchOne(
            "SELECT 1 FROM downloaded_file WHERE directory_id = :directory AND relative_path LIKE :pattern ESCAPE '\\'",
            ['directory' => $directory->getId(), 'pattern' => addcslashes($stem, '%_\\').'.%'],
        );
        if (false === $shared) {
            @unlink($infoJson);
        }
    }

    private function removeEmptyParents(string $path, string $root): void
    {
        $root = rtrim($root, '/');
        // rmdir() only removes empty directories
        while ('' !== $root && str_starts_with($path, $root.'/') && @rmdir($path)) {
            $path = \dirname($path);
        }
    }
}
