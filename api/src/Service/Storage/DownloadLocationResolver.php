<?php

namespace App\Service\Storage;

use App\Entity\DownloadDirectory;
use App\Repository\DownloadDirectoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Maps absolute paths (as printed by the download tools) to download locations.
 */
class DownloadLocationResolver implements ResetInterface
{
    /** @var list<array{id: int, prefixes: list<string>}>|null Directories, longest path first */
    private ?array $directories = null;

    public function __construct(
        private readonly DownloadDirectoryRepository $directoryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The directory with the longest matching path prefix and the remainder of the path, or null (logged)
     * when the path is in no directory. Both the given and the symlink-resolved forms are matched.
     */
    public function fromAbsolute(string $path): ?DownloadLocation
    {
        $candidates = array_unique([Paths::normalize($path), Paths::canonicalize($path)]);

        $best = null;
        foreach ($this->directories() as $directory) {
            foreach ($directory['prefixes'] as $prefix) {
                foreach ($candidates as $candidate) {
                    $relativePath = Paths::relativeTo($candidate, $prefix);
                    if (null !== $relativePath && (null === $best || \strlen($prefix) > $best['length'])) {
                        $best = ['id' => $directory['id'], 'relativePath' => $relativePath, 'length' => \strlen($prefix)];
                    }
                }
            }
        }

        if (null === $best) {
            $this->logger->warning('Path is outside every download directory', ['path' => $path]);

            return null;
        }

        return new DownloadLocation($this->entityManager->getReference(DownloadDirectory::class, $best['id']), $best['relativePath']);
    }

    public function reset(): void
    {
        $this->directories = null;
    }

    /**
     * @return list<array{id: int, prefixes: list<string>}>
     */
    private function directories(): array
    {
        if (null === $this->directories) {
            $this->directories = [];
            foreach ($this->directoryRepository->findAll() as $directory) {
                $this->directories[] = [
                    'id' => (int) $directory->getId(),
                    'prefixes' => array_values(array_unique([Paths::normalize($directory->getPath()), Paths::canonicalize($directory->getPath())])),
                ];
            }
        }

        return $this->directories;
    }
}
