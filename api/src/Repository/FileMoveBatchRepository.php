<?php

namespace App\Repository;

use App\Entity\DownloadDirectory;
use App\Entity\FileMoveBatch;
use App\Entity\Site;
use App\Enum\FileMoveBatchStatus;
use App\Enum\FileMoveItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Batch counters and status changes are single SQL statements: many workers update a batch concurrently.
 *
 * @extends ServiceEntityRepository<FileMoveBatch>
 */
class FileMoveBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileMoveBatch::class);
    }

    public function findActiveForSite(Site $site): ?FileMoveBatch
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.site = :site AND b.status IN (:active)')
            ->setParameter('site', $site)
            ->setParameter('active', FileMoveBatchStatus::activeValues())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** An active batch moving files from or to the directory */
    public function findActiveTouchingDirectory(DownloadDirectory $directory): ?FileMoveBatch
    {
        return $this->createQueryBuilder('b')
            ->andWhere('(b.sourceDirectory = :directory OR b.targetDirectory = :directory) AND b.status IN (:active)')
            ->setParameter('directory', $directory)
            ->setParameter('active', FileMoveBatchStatus::activeValues())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Moves the batch from QUEUED to RUNNING; false if another worker did (or it was cancelled meanwhile).
     */
    public function claimStart(int $batchId): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE file_move_batch SET status = :running, started_at = NOW() WHERE id = :id AND status = :queued',
            ['id' => $batchId, 'running' => FileMoveBatchStatus::RUNNING->value, 'queued' => FileMoveBatchStatus::QUEUED->value],
        );
    }

    public function setBytesTotal(int $batchId, int $bytes): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE file_move_batch SET bytes_total = :bytes WHERE id = :id', ['id' => $batchId, 'bytes' => $bytes]);
    }

    /**
     * @param 'moved'|'skipped'|'failed' $counter
     */
    public function increment(int $batchId, string $counter, int $bytes = 0): void
    {
        \assert(\in_array($counter, ['moved', 'skipped', 'failed'], true));
        $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE file_move_batch SET {$counter} = {$counter} + 1, bytes_moved = bytes_moved + :bytes WHERE id = :id",
            ['id' => $batchId, 'bytes' => $bytes],
        );
    }

    /** Keeps the first FileMoveBatch::MAX_ERRORS errors */
    public function addError(int $batchId, int $fileId, string $path, string $error): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE file_move_batch SET errors = errors || jsonb_build_array(jsonb_build_object(\'fileId\', :file::int, \'path\', :path::text, \'error\', :error::text))
            WHERE id = :id AND jsonb_array_length(errors) < :max',
            ['id' => $batchId, 'file' => $fileId, 'path' => $path, 'error' => mb_substr($error, 0, 500), 'max' => FileMoveBatch::MAX_ERRORS],
        );
    }

    /**
     * Completes a running batch whose items are all done; false if items remain or it isn't running.
     * Called after each item: the worker committing the last item sees every other one done.
     */
    public function finishIfDone(int $batchId): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE file_move_batch SET finished_at = NOW(), status = CASE WHEN failed > 0 THEN :withErrors ELSE :completed END
            WHERE id = :id AND status = :running
            AND NOT EXISTS (SELECT 1 FROM file_move_item i WHERE i.batch_id = :id AND i.status IN (:active))',
            [
                'id' => $batchId,
                'running' => FileMoveBatchStatus::RUNNING->value,
                'completed' => FileMoveBatchStatus::COMPLETED->value,
                'withErrors' => FileMoveBatchStatus::COMPLETED_WITH_ERRORS->value,
                'active' => [FileMoveItemStatus::PENDING->value, FileMoveItemStatus::MOVING->value],
            ],
            ['active' => ArrayParameterType::STRING],
        );
    }

    public function fail(int $batchId, string $error): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'UPDATE file_move_item SET status = :skipped, error = :error, updated_at = NOW() WHERE batch_id = :id AND status = :pending',
            ['id' => $batchId, 'skipped' => FileMoveItemStatus::SKIPPED->value, 'pending' => FileMoveItemStatus::PENDING->value, 'error' => 'batch failed'],
        );
        $connection->executeStatement(
            'UPDATE file_move_batch SET status = :failed, finished_at = NOW(), errors = errors || jsonb_build_array(jsonb_build_object(\'fileId\', 0, \'path\', \'\', \'error\', :error::text)) WHERE id = :id',
            ['id' => $batchId, 'failed' => FileMoveBatchStatus::FAILED->value, 'error' => $error],
        );
    }

    /**
     * Cancels an active batch: its pending items are skipped; items being moved finish.
     *
     * @return bool false if the batch isn't active
     */
    public function cancel(int $batchId): bool
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($batchId): bool {
            $connection = $this->getEntityManager()->getConnection();
            $updated = $connection->executeStatement(
                'UPDATE file_move_batch SET status = :cancelled, finished_at = NOW() WHERE id = :id AND status IN (:active)',
                ['id' => $batchId, 'cancelled' => FileMoveBatchStatus::CANCELLED->value, 'active' => FileMoveBatchStatus::activeValues()],
                ['active' => ArrayParameterType::STRING],
            );
            if (1 !== $updated) {
                return false;
            }
            $skipped = $connection->executeStatement(
                'UPDATE file_move_item SET status = :skipped, error = :error, updated_at = NOW() WHERE batch_id = :id AND status = :pending',
                ['id' => $batchId, 'skipped' => FileMoveItemStatus::SKIPPED->value, 'pending' => FileMoveItemStatus::PENDING->value, 'error' => 'cancelled'],
            );
            $connection->executeStatement('UPDATE file_move_batch SET skipped = skipped + :n WHERE id = :id', ['id' => $batchId, 'n' => $skipped]);

            return true;
        });
    }

    /**
     * Claims a progress update: true at most once per second per batch, across workers.
     */
    public function claimProgressPublish(int $batchId): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE file_move_batch SET progress_published_at = NOW() WHERE id = :id
            AND (progress_published_at IS NULL OR progress_published_at < NOW() - INTERVAL '1 second')",
            ['id' => $batchId],
        );
    }

    /** @return list<int> ids of running batches */
    public function findRunningIds(): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM file_move_batch WHERE status = :running ORDER BY id',
            ['running' => FileMoveBatchStatus::RUNNING->value],
        ));
    }

    /** @return list<int> ids of batches queued for longer than the given time */
    public function findStaleQueuedIds(\DateTimeImmutable $before): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM file_move_batch WHERE status = :queued AND created_at < :before ORDER BY id',
            ['queued' => FileMoveBatchStatus::QUEUED->value, 'before' => $before->format('Y-m-d H:i:s')],
        ));
    }
}
