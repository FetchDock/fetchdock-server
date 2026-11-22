<?php

namespace App\Service\Storage;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadJob;
use App\Entity\Site;
use App\Repository\DownloadDirectoryRepository;

/**
 * Picks the directory new downloads are written to: the site's directory, otherwise the default one (ADR 0011).
 */
final readonly class DownloadTargets
{
    public function __construct(private DownloadDirectoryRepository $directoryRepository)
    {
    }

    public function forSite(?Site $site): DownloadDirectory
    {
        return $site?->getDownloadDirectory() ?? $this->directoryRepository->findDefault();
    }

    /**
     * The job's directory: the one captured when the worker started it, otherwise its site's current one.
     */
    public function forJob(DownloadJob $job): DownloadDirectory
    {
        return $job->getDownloadDirectory() ?? $this->forSite($job->getSite());
    }

    /** Whether the directory is mounted and writable in this container */
    public static function isAvailable(DownloadDirectory $directory): bool
    {
        return is_dir($directory->getPath()) && is_writable($directory->getPath());
    }
}
