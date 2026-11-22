<?php

namespace App\Service\Storage;

use App\Entity\DownloadDirectory;

/**
 * Where a file lives: a download directory and a path inside it.
 */
final readonly class DownloadLocation
{
    public function __construct(
        public DownloadDirectory $directory,
        public string $relativePath,
    ) {
    }

    public function absolutePath(): string
    {
        return $this->directory->resolve($this->relativePath);
    }
}
