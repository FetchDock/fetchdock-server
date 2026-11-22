<?php

namespace App\Command;

use App\Message\MoveFileMessage;
use App\Message\StartFileMoveBatchMessage;
use App\Repository\FileMoveBatchRepository;
use App\Repository\FileMoveItemRepository;
use App\Service\FileMove\FileMoveProgressPublisher;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Resumes file moves that a worker left behind (ADR 0011). Runs hourly in the workers (Symfony Scheduler); idempotent,
 * so every worker running it is fine:
 * - items stuck in "moving" for longer than --stuck-after are dispatched again;
 * - batches still queued after that time are started again;
 * - when the file-moves queue is empty, the pending items of running batches are dispatched again (lost messages);
 * - running batches whose items are all done are completed.
 */
#[AsCommand(name: 'app:file-moves:recover', description: 'Resume stuck file move batches')]
#[AsPeriodicTask(frequency: '1 hour', jitter: 300)]
final readonly class RecoverFileMovesCommand
{
    private const int CHUNK = 1000;

    public function __construct(
        private FileMoveBatchRepository $batchRepository,
        private FileMoveItemRepository $itemRepository,
        private FileMoveProgressPublisher $publisher,
        private MessageBusInterface $bus,
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $receivers,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconds after which a moving item or a queued batch counts as stuck')]
        int $stuckAfter = 3600,
    ): int {
        $before = new \DateTimeImmutable(sprintf('-%d seconds', max(60, $stuckAfter)));

        $released = $this->itemRepository->releaseStuck($before);
        foreach ($released as $itemId) {
            $this->bus->dispatch(new MoveFileMessage($itemId));
        }

        $restarted = $this->batchRepository->findStaleQueuedIds($before);
        foreach ($restarted as $batchId) {
            $this->bus->dispatch(new StartFileMoveBatchMessage($batchId));
        }

        $redispatched = 0;
        $completed = 0;
        $queueEmpty = 0 === $this->queuedMessages();
        foreach ($this->batchRepository->findRunningIds() as $batchId) {
            if ($queueEmpty) {
                $afterId = 0;
                while ([] !== $itemIds = $this->itemRepository->findPendingIds($batchId, $afterId, self::CHUNK)) {
                    $afterId = end($itemIds);
                    foreach ($itemIds as $itemId) {
                        $this->bus->dispatch(new MoveFileMessage($itemId));
                        ++$redispatched;
                    }
                }
            }
            if ($this->batchRepository->finishIfDone($batchId)) {
                ++$completed;
                $this->publisher->publish($batchId, force: true);
            }
        }

        $io->success(sprintf(
            'Released %d stuck items, restarted %d queued batches, dispatched %d pending items again, completed %d batches.',
            \count($released), \count($restarted), $redispatched, $completed,
        ));

        return Command::SUCCESS;
    }

    /** Messages waiting in the file-moves queue, or null when the transport can't tell (pending items are left alone) */
    private function queuedMessages(): ?int
    {
        $receiver = $this->receivers->has('file-moves') ? $this->receivers->get('file-moves') : null;

        return $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null;
    }
}
