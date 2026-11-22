<?php

namespace App\Handler;

use App\Entity\FileMoveBatch;
use App\Entity\FileMoveItem;
use App\Message\MoveFileMessage;
use App\Message\StartFileMoveBatchMessage;
use App\Repository\FileMoveBatchRepository;
use App\Repository\FileMoveItemRepository;
use App\Service\FileMove\FileMoveProgressPublisher;
use App\Service\Storage\DownloadTargets;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts a planned batch in the worker: checks that the target is mounted here, measures the pending files
 * (bytesTotal) and dispatches one MoveFileMessage per file. Only one worker starts a batch (claimStart).
 */
#[AsMessageHandler]
final readonly class StartFileMoveBatchHandler
{
    private const int CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileMoveBatchRepository $batchRepository,
        private FileMoveItemRepository $itemRepository,
        private FileMoveProgressPublisher $publisher,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartFileMoveBatchMessage $message): void
    {
        $batchId = $message->batchId;
        if (!$this->batchRepository->claimStart($batchId)) {
            return;
        }

        $batch = $this->entityManager->find(FileMoveBatch::class, $batchId);
        $target = $batch?->getTargetDirectory();
        if (null === $target || !DownloadTargets::isAvailable($target)) {
            $this->batchRepository->fail($batchId, sprintf('The target directory %s is not mounted or not writable in the worker. The api and the worker need the same mounts.', $target?->getPath() ?? '(deleted)'));
            $this->logger->error('File move batch failed: the target directory is not available in the worker', ['batch' => $batchId]);
            $this->publisher->publish($batchId, force: true);

            return;
        }

        $bytesTotal = 0;
        $afterId = 0;
        while ([] !== $itemIds = $this->itemRepository->findPendingIds($batchId, $afterId, self::CHUNK)) {
            $afterId = end($itemIds);
            foreach ($itemIds as $itemId) {
                $item = $this->entityManager->find(FileMoveItem::class, $itemId);
                $source = $item?->getSourceDirectory()?->resolve($item->getSourceRelativePath());
                $size = null !== $source && is_file($source) ? filesize($source) : false;
                $this->itemRepository->setSize($itemId, false === $size ? null : $size);
                $bytesTotal += false === $size ? 0 : $size;
                $this->bus->dispatch(new MoveFileMessage($itemId));
            }
            $this->batchRepository->setBytesTotal($batchId, $bytesTotal);
            $this->entityManager->clear();
        }

        $this->logger->info('File move batch started', ['batch' => $batchId, 'bytes' => $bytesTotal]);
        // A batch without pending files is done straight away
        $this->batchRepository->finishIfDone($batchId);
        $this->publisher->publish($batchId, force: true);
    }
}
