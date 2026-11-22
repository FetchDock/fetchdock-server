<?php

namespace App\Repository;

use App\Entity\FileMoveItem;
use App\Enum\FileMoveBatchStatus;
use App\Enum\FileMoveItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FileMoveItem>
 */
class FileMoveItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileMoveItem::class);
    }

    /**
     * Marks a pending item of a running batch as moving; false if it isn't pending (done, cancelled or claimed by
     * another worker) or its batch isn't running. The claim makes a redelivered message harmless.
     */
    public function claim(int $itemId): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE file_move_item SET status = :moving, attempts = attempts + 1, updated_at = NOW()
            WHERE id = :id AND status = :pending
            AND EXISTS (SELECT 1 FROM file_move_batch b WHERE b.id = batch_id AND b.status = :running)',
            ['id' => $itemId, 'moving' => FileMoveItemStatus::MOVING->value, 'pending' => FileMoveItemStatus::PENDING->value, 'running' => FileMoveBatchStatus::RUNNING->value],
        );
    }

    public function finish(int $itemId, FileMoveItemStatus $status, ?string $error = null): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE file_move_item SET status = :status, error = :error, updated_at = NOW() WHERE id = :id',
            ['id' => $itemId, 'status' => $status->value, 'error' => $error],
        );
    }

    public function setSize(int $itemId, ?int $size): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE file_move_item SET size = :size WHERE id = :id', ['id' => $itemId, 'size' => $size]);
    }

    /**
     * Pending item ids of a batch, by id, after the given id.
     *
     * @return list<int>
     */
    public function findPendingIds(int $batchId, int $afterId, int $limit): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM file_move_item WHERE batch_id = :batch AND status = :pending AND id > :after ORDER BY id LIMIT '.$limit,
            ['batch' => $batchId, 'pending' => FileMoveItemStatus::PENDING->value, 'after' => $afterId],
        ));
    }

    /**
     * Items stuck in "moving" (their worker died): back to pending in running batches, to be dispatched again;
     * skipped in batches that stopped meanwhile (cancelled or failed).
     *
     * @return list<int> ids of the items to dispatch again
     */
    public function releaseStuck(\DateTimeImmutable $before): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $parameters = ['moving' => FileMoveItemStatus::MOVING->value, 'running' => FileMoveBatchStatus::RUNNING->value, 'before' => $before->format('Y-m-d H:i:s')];
        $connection->executeStatement(
            "UPDATE file_move_item i SET status = :skipped, error = 'interrupted', updated_at = NOW() FROM file_move_batch b
            WHERE b.id = i.batch_id AND b.status <> :running AND i.status = :moving AND i.updated_at < :before",
            $parameters + ['skipped' => FileMoveItemStatus::SKIPPED->value],
        );

        return array_map('intval', $connection->fetchFirstColumn(
            'UPDATE file_move_item i SET status = :pending, updated_at = NOW() FROM file_move_batch b
            WHERE b.id = i.batch_id AND b.status = :running AND i.status = :moving AND i.updated_at < :before RETURNING i.id',
            $parameters + ['pending' => FileMoveItemStatus::PENDING->value],
        ));
    }
}
