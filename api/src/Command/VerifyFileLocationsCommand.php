<?php

namespace App\Command;

use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadedFile;
use App\Repository\DownloadDirectoryRepository;
use App\Repository\DownloadedFileRepository;
use App\Service\Storage\DownloadLocationResolver;
use App\Service\Storage\DownloadRoots;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports download directories and file rows that don't fit the storage model (ADR 0011):
 * directories outside DOWNLOAD_ROOTS or not mounted, and files whose path isn't inside their directory
 * (legacy rows the migration couldn't map keep their absolute path).
 */
#[AsCommand(
    name: 'app:files:verify-locations',
    description: 'Report download directories and file locations that do not fit, optionally re-mapping legacy absolute paths',
)]
final class VerifyFileLocationsCommand
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly DownloadDirectoryRepository $directoryRepository,
        private readonly DownloadedFileRepository $fileRepository,
        private readonly DownloadLocationResolver $locationResolver,
        private readonly DownloadRoots $roots,
        private readonly EntityManagerInterface $entityManager,
        private readonly SoftDeleteFilter $softDeleteFilter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Move legacy absolute paths into the directory that contains them, when its location is free')]
        bool $fix = false,
        #[Option('Also report files missing on disk (reads every row)')]
        bool $checkDisk = false,
    ): int {
        $problems = $this->verifyDirectories($io);

        $problems += $this->softDeleteFilter->disabled(fn (): int => $this->verifyFiles($io, $fix, $checkDisk));

        if (0 === $problems) {
            $io->success('Every download directory and file location fits.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d problem(s) found.', $problems));

        return Command::FAILURE;
    }

    private function verifyDirectories(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->directoryRepository->findAll() as $directory) {
            $path = $directory->getPath();
            $issue = match (true) {
                !$this->roots->contains($path) => sprintf('outside DOWNLOAD_ROOTS (%s)', implode(', ', $this->roots->all())),
                !is_dir($path) => 'not a directory or not mounted',
                !is_writable($path) => 'not writable',
                default => null,
            };
            if (null !== $issue) {
                $rows[] = [$directory->getId(), $directory->getName(), $path, $issue];
            }
        }

        if ([] !== $rows) {
            $io->section('Directories');
            $io->table(['ID', 'Name', 'Path', 'Problem'], $rows);
        }

        return \count($rows);
    }

    private function verifyFiles(SymfonyStyle $io, bool $fix, bool $checkDisk): int
    {
        $rows = [];
        $fixed = 0;

        $query = $this->fileRepository->createQueryBuilder('f')->orderBy('f.id');
        if (!$checkDisk) {
            $query->where("f.relativePath LIKE '/%' OR f.relativePath LIKE '../%' OR f.relativePath LIKE '%/../%'");
        }

        $processed = 0;
        /** @var DownloadedFile $file */
        foreach ($query->getQuery()->toIterable() as $file) {
            $relativePath = (string) $file->getRelativePath();
            $issue = null;

            if (str_starts_with($relativePath, '/')) {
                $location = $fix ? $this->locationResolver->fromAbsolute($relativePath) : null;
                if (null !== $location && null === $this->fileRepository->findOneByLocation($location->directory, $location->relativePath)) {
                    $file->setLocation($location->directory, $location->relativePath);
                    ++$fixed;
                } else {
                    $issue = match (true) {
                        !$fix => 'absolute path (legacy)',
                        null === $location => 'outside every directory',
                        default => 'its location in a directory is already used',
                    };
                }
            } elseif ('..' === $relativePath || str_starts_with($relativePath, '../') || str_contains($relativePath, '/../')) {
                $issue = 'escapes its directory';
            } elseif ($checkDisk && !is_file((string) $file->getPath())) {
                $issue = 'missing on disk';
            }

            if (null !== $issue) {
                $rows[] = [$file->getId(), $file->getPath(), $issue];
            }

            if (0 === ++$processed % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->locationResolver->reset();
            }
        }
        $this->entityManager->flush();

        if ($fixed > 0) {
            $io->note(sprintf('Re-mapped %d legacy file location(s).', $fixed));
        }
        if ([] !== $rows) {
            $io->section('Files');
            $io->table(['ID', 'Path', 'Problem'], $rows);
        }

        return \count($rows);
    }
}
