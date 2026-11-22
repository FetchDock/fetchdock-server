<?php

namespace App\Handler;

use App\Entity\FileMoveItem;
use App\Enum\FileMoveItemStatus;
use App\Message\MoveFileMessage;
use App\Repository\DownloadedFileRepository;
use App\Repository\FileMoveBatchRepository;
use App\Repository\FileMoveItemRepository;
use App\Service\FileMove\FileMoveFailure;
use App\Service\FileMove\FileMoveProgressPublisher;
use App\Service\FileMove\FileMover;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves one file of a batch (ADR 0011), idempotently:
 * 1. claim the item (pending → moving, only while the batch runs), so a redelivered message does nothing;
 * 2. transfer the file without any transaction open (FileMover never overwrites, and keeps the source);
 * 3. commit the new location in a short transaction, only if the file is still at the item's source;
 * 4. remove the source only after the commit, then move the sidecar files the same way.
 *
 * I/O errors are rethrown for Messenger to retry, up to MAX_ATTEMPTS; then the item fails. The serving of a file
 * reads the committed location, so it gets the source before the commit and the target after.
 */
#[AsMessageHandler]
final readonly class MoveFileHandler
{
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileMoveItemRepository $itemRepository,
        private FileMoveBatchRepository $batchRepository,
        private DownloadedFileRepository $fileRepository,
        private FileMover $mover,
        private FileMoveProgressPublisher $publisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MoveFileMessage $message): void
    {
        if (!$this->itemRepository->claim($message->itemId)) {
            return;
        }

        $item = $this->entityManager->find(FileMoveItem::class, $message->itemId);
        $this->entityManager->refresh($item);
        $batchId = (int) $item->getBatch()->getId();
        $fileId = (int) $item->getFile()->getId();
        $source = $item->getSourceDirectory()?->resolve($item->getSourceRelativePath());
        $target = $item->getBatch()->getTargetDirectory();
        $targetPath = $target?->resolve($item->getSourceRelativePath());

        try {
            if (null === $source || null === $target || null === $targetPath) {
                throw new FileMoveFailure('the source or target directory was deleted');
            }

            $mode = $this->mover->transfer($source, $targetPath, $target->isVerifyChecksum());
            try {
                $this->commit($item, (int) $target->getId());
            } catch (\Throwable $e) {
                $this->mover->undo($source, $targetPath);
                throw $e;
            }
            $this->mover->finish($source);
            $this->moveSidecars($item, $source, $targetPath, $batchId, $fileId);
            $this->logger->info('Moved a file', ['batch' => $batchId, 'file' => $fileId, 'mode' => $mode, 'target' => $targetPath]);
        } catch (FileMoveFailure $e) {
            $this->itemRepository->finish($message->itemId, $e->skip ? FileMoveItemStatus::SKIPPED : FileMoveItemStatus::FAILED, $e->getMessage());
            $this->batchRepository->increment($batchId, $e->skip ? 'skipped' : 'failed');
            if (!$e->skip) {
                $this->batchRepository->addError($batchId, $fileId, (string) $source, $e->getMessage());
            }
        } catch (\Throwable $e) {
            if ($item->getAttempts() < self::MAX_ATTEMPTS) {
                // Messenger retries the message; the item must be pending again to be claimed
                $this->itemRepository->finish($message->itemId, FileMoveItemStatus::PENDING, $e->getMessage());
                throw $e;
            }
            $this->itemRepository->finish($message->itemId, FileMoveItemStatus::FAILED, $e->getMessage());
            $this->batchRepository->increment($batchId, 'failed');
            $this->batchRepository->addError($batchId, $fileId, (string) $source, $e->getMessage());
            $this->logger->error('Unable to move a file', ['batch' => $batchId, 'file' => $fileId, 'exception' => $e]);
        } finally {
            $finished = $this->batchRepository->finishIfDone($batchId);
            $this->publisher->publish($batchId, force: $finished);
        }
    }

    /**
     * Commits the new location if the file is still at the item's source (a relocation or another move may have
     * changed it meanwhile), and counts the move.
     */
    private function commit(FileMoveItem $item, int $targetId): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            $this->entityManager->wrapInTransaction(function () use ($connection, $item, $targetId): void {
                $updated = $connection->executeStatement(
                    'UPDATE downloaded_file SET directory_id = :target WHERE id = :id AND directory_id = :source AND relative_path = :path',
                    ['target' => $targetId, 'id' => $item->getFile()->getId(), 'source' => $item->getSourceDirectory()?->getId(), 'path' => $item->getSourceRelativePath()],
                );
                if (1 !== $updated) {
                    throw FileMoveFailure::skip('the file is no longer at the source location');
                }
                $connection->executeStatement(
                    'UPDATE file_move_item SET status = :moved, error = NULL, updated_at = NOW() WHERE id = :id',
                    ['moved' => FileMoveItemStatus::MOVED->value, 'id' => $item->getId()],
                );
                $this->batchRepository->increment((int) $item->getBatch()->getId(), 'moved', (int) $item->getSize());
            });
        } catch (UniqueConstraintViolationException) {
            throw new FileMoveFailure('another file record already uses the target location');
        }
    }

    /**
     * Moves the untracked files named after the media file, "<stem>.*": yt-dlp's subtitles (<stem>.<lang>.ass),
     * live chat, .info.json, .description and thumbnails. Best effort: a sidecar that can't move stays at the source
     * and is reported, the media file stays moved. Tracked files (e.g. a .desktop shortcut row) have their own items.
     */
    private function moveSidecars(FileMoveItem $item, string $source, string $target, int $batchId, int $fileId): void
    {
        $sourceStem = preg_replace('/\.[^.\/]+$/', '', $source);
        $targetStem = preg_replace('/\.[^.\/]+$/', '', $target);
        $relativeStem = preg_replace('/\.[^.\/]+$/', '', $item->getSourceRelativePath());

        foreach (glob(addcslashes($sourceStem, '*?[]\\').'.*', GLOB_NOSORT) ?: [] as $sidecar) {
            $suffix = substr($sidecar, \strlen($sourceStem));
            if ($sidecar === $source || !is_file($sidecar) || str_ends_with($sidecar, FileMover::PART_SUFFIX)
                || (null !== $item->getSourceDirectory() && null !== $this->fileRepository->findOneByLocation($item->getSourceDirectory(), $relativeStem.$suffix))) {
                continue;
            }
            try {
                $this->mover->transfer($sidecar, $targetStem.$suffix, false);
                $this->mover->finish($sidecar);
            } catch (\Throwable $e) {
                $this->batchRepository->addError($batchId, $fileId, $sidecar, 'sidecar not moved: '.$e->getMessage());
            }
        }
    }
}
