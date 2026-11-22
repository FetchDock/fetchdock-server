<?php

namespace App\Service\FileMove;

use App\Entity\DownloadDirectory;
use App\Entity\FileMoveBatch;
use App\Entity\Site;
use App\Enum\DownloadStateEnum;
use App\Enum\FileMoveItemStatus;
use App\Enum\FileMoveScope;
use App\Message\StartFileMoveBatchMessage;
use App\Repository\FileMoveBatchRepository;
use App\Service\Storage\DownloadTargets;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans file move batches (ADR 0011): in one transaction, creates the batch and one item per selected file, then
 * dispatches the batch to the worker, which measures the files and dispatches one message per file.
 *
 * Items are skipped (and reported) for files of PENDING or IN_PROGRESS jobs, files already in an active batch,
 * legacy rows with an absolute path and, in site batches, files that jobs of other sites use too.
 */
final readonly class FileMovePlanner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileMoveBatchRepository $batchRepository,
        private DownloadTargets $downloadTargets,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Moves the site's files that aren't in its effective directory there. 409 if a batch of the site is active.
     */
    public function planSite(Site $site, ?string $requestedBy): FileMoveBatch
    {
        $this->assertNoActiveSiteBatch($site);
        $target = $this->downloadTargets->forSite($site);
        $this->assertAvailable($target);

        $batch = new FileMoveBatch(FileMoveScope::SITE, $target, $requestedBy)->setSite($site);

        return $this->plan($batch, function (int $batchId) use ($site, $target): void {
            $siteJob = 'FROM downloaded_file_download_job l JOIN download_job j ON j.id = l.download_job_id WHERE l.downloaded_file_id = f.id';
            $this->insertItems(
                $batchId,
                "f.directory_id <> :target AND EXISTS (SELECT 1 {$siteJob} AND j.site_id = :site)",
                "WHEN EXISTS (SELECT 1 {$siteJob} AND j.site_id IS DISTINCT FROM :site) THEN 'shared with another site'",
                ['target' => $target->getId(), 'site' => $site->getId()],
            );
        });
    }

    /**
     * Moves every file of the source directory to the target, whatever its site. With $reassignSites, the sites using
     * the source switch to the target once the batch is planned, so new downloads go there straight away.
     */
    public function planDirectory(DownloadDirectory $source, DownloadDirectory $target, bool $reassignSites, ?string $requestedBy): FileMoveBatch
    {
        if ($source === $target || $source->getId() === $target->getId()) {
            throw new UnprocessableEntityHttpException('The target directory is the source directory.');
        }
        foreach ([$source, $target] as $directory) {
            if (null !== $active = $this->batchRepository->findActiveTouchingDirectory($directory)) {
                throw new ConflictHttpException(sprintf('File move batch %d is already moving files of the directory "%s".', $active->getId(), $directory->getName()));
            }
        }
        $this->assertAvailable($target);

        $batch = new FileMoveBatch(FileMoveScope::DIRECTORY, $target, $requestedBy)->setSourceDirectory($source);

        return $this->plan($batch, function (int $batchId) use ($source, $target, $reassignSites): void {
            $this->insertItems($batchId, 'f.directory_id = :source', null, ['source' => $source->getId()]);
            if ($reassignSites) {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE site SET download_directory_id = :target WHERE download_directory_id = :source',
                    ['target' => $target->getId(), 'source' => $source->getId()],
                );
            }
        });
    }

    public function assertNoActiveSiteBatch(Site $site): void
    {
        if (null !== $active = $this->batchRepository->findActiveForSite($site)) {
            throw new ConflictHttpException(sprintf('File move batch %d of this site is still active: wait for it or cancel it first.', $active->getId()));
        }
    }

    private function assertAvailable(DownloadDirectory $target): void
    {
        if (!DownloadTargets::isAvailable($target)) {
            throw new UnprocessableEntityHttpException(sprintf('The directory "%s" (%s) is not mounted or not writable.', $target->getName(), $target->getPath()));
        }
    }

    /**
     * @param callable(int): void $insertItems
     */
    private function plan(FileMoveBatch $batch, callable $insertItems): FileMoveBatch
    {
        $this->entityManager->wrapInTransaction(function () use ($batch, $insertItems): void {
            $this->entityManager->persist($batch);
            $this->entityManager->flush();
            $insertItems((int) $batch->getId());
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE file_move_batch b SET
                    total = (SELECT COUNT(*) FROM file_move_item i WHERE i.batch_id = b.id),
                    skipped = (SELECT COUNT(*) FROM file_move_item i WHERE i.batch_id = b.id AND i.status = :skipped)
                WHERE b.id = :id',
                ['id' => $batch->getId(), 'skipped' => FileMoveItemStatus::SKIPPED->value],
            );
        });
        $this->entityManager->refresh($batch);

        $this->bus->dispatch(new StartFileMoveBatchMessage((int) $batch->getId()));
        $this->logger->info('File move batch planned', [
            'batch' => $batch->getId(),
            'scope' => $batch->getScope()->value,
            'site' => $batch->getSite()?->getSlug(),
            'source' => $batch->getSourceDirectory()?->getId(),
            'target' => $batch->getTargetDirectory()?->getId(),
            'total' => $batch->getTotal(),
            'skipped' => $batch->getSkipped(),
            'admin' => $batch->getRequestedBy(),
        ]);

        return $batch;
    }

    /**
     * One item per file matching $selection, pending unless a skip reason applies.
     *
     * @param array<string, mixed> $parameters
     */
    private function insertItems(int $batchId, string $selection, ?string $extraSkipReasons, array $parameters): void
    {
        $busyJob = 'SELECT 1 FROM downloaded_file_download_job l JOIN download_job j ON j.id = l.download_job_id
            WHERE l.downloaded_file_id = f.id AND j.deleted_at IS NULL AND j.state IN (:pendingState, :inProgressState)';
        $activeItem = 'SELECT 1 FROM file_move_item a WHERE a.file_id = f.id AND a.status IN (:pending, :moving)';

        // ON CONFLICT: a file that another batch claimed meanwhile is left out (partial unique index on active items)
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO file_move_item (batch_id, file_id, source_directory_id, source_relative_path, status, error, attempts, updated_at)
            SELECT :batch, s.id, s.directory_id, s.relative_path, CASE WHEN s.reason IS NULL THEN :pending ELSE :skipped END, s.reason, 0, NOW()
            FROM (
                SELECT f.id, f.directory_id, f.relative_path, CASE
                    WHEN starts_with(f.relative_path, '/') THEN 'legacy absolute path (see app:files:verify-locations)'
                    WHEN EXISTS ({$busyJob}) THEN 'a job using the file is pending or in progress'
                    ".($extraSkipReasons ?? '')."
                    WHEN EXISTS ({$activeItem}) THEN 'in another batch'
                    ELSE NULL END AS reason
                FROM downloaded_file f
                WHERE {$selection}
                ORDER BY f.id
            ) s
            ON CONFLICT DO NOTHING",
            $parameters + [
                'batch' => $batchId,
                'pending' => FileMoveItemStatus::PENDING->value,
                'moving' => FileMoveItemStatus::MOVING->value,
                'skipped' => FileMoveItemStatus::SKIPPED->value,
                'pendingState' => DownloadStateEnum::PENDING->value,
                'inProgressState' => DownloadStateEnum::IN_PROGRESS->value,
            ],
        );
    }
}
