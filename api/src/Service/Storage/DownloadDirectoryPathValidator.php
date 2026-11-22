<?php

namespace App\Service\Storage;

use App\Entity\DownloadDirectory;
use App\Repository\DownloadDirectoryRepository;

/**
 * Checks a download directory path for the admin API (ADR 0011): absolute, inside DOWNLOAD_ROOTS (symlinks resolved),
 * an existing writable directory, and neither inside nor around another directory (a file has one location).
 */
final readonly class DownloadDirectoryPathValidator
{
    public function __construct(
        private DownloadRoots $roots,
        private DownloadDirectoryRepository $directoryRepository,
    ) {
    }

    /** The path as stored: symlinks resolved, no trailing slash */
    public function canonicalize(string $path): string
    {
        return Paths::canonicalize($path);
    }

    /**
     * @return string|null the problem, or null if the path can be used
     */
    public function validate(string $path, ?DownloadDirectory $self = null): ?string
    {
        if (!str_starts_with($path, '/')) {
            return 'The path must be absolute.';
        }

        $canonical = $this->canonicalize($path);
        if (!$this->roots->contains($canonical)) {
            return sprintf('The path must be inside DOWNLOAD_ROOTS (%s).', implode(', ', $this->roots->all()));
        }
        if (!is_dir($canonical)) {
            return 'The path does not exist or is not a directory in the api container.';
        }
        if (!is_writable($canonical)) {
            return 'The directory is not writable in the api container.';
        }

        foreach ($this->directoryRepository->findAll() as $other) {
            if ($other === $self) {
                continue;
            }
            if (Paths::isWithin($canonical, $other->getPath()) || Paths::isWithin($other->getPath(), $canonical)) {
                return sprintf('The path overlaps the directory "%s" (%s).', $other->getName(), $other->getPath());
            }
        }

        return null;
    }
}
