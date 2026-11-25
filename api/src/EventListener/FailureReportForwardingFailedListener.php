<?php

namespace App\EventListener;

use App\Entity\FailureReport;
use App\Entity\FailureReportUpstreamComment;
use App\Enum\FailureReportForwarding;
use App\Enum\UpstreamCommentState;
use App\Message\DeleteUpstreamReportMessage;
use App\Message\ForwardFailureReportMessage;
use App\Message\ForwardUpstreamCommentMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Marks a report or comment failed once Messenger gives up on sending it, so admins see it and can forward it again.
 */
#[AsEventListener]
final readonly class FailureReportForwardingFailedListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $reason = 'The collector could not be reached: '.$event->getThrowable()->getMessage();
        if ($message instanceof ForwardFailureReportMessage) {
            $report = $this->entityManager->find(FailureReport::class, Uuid::fromString($message->reportId));
            if (null !== $report && FailureReportForwarding::PENDING === $report->getForwarding()) {
                $report->setForwarding(FailureReportForwarding::FAILED, mb_substr($reason, 0, 2000))->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        } elseif ($message instanceof ForwardUpstreamCommentMessage) {
            $comment = $this->entityManager->find(FailureReportUpstreamComment::class, Uuid::fromString($message->commentId));
            if (null !== $comment && UpstreamCommentState::PENDING === $comment->getState()) {
                $comment->markFailed(mb_substr($reason, 0, 2000));
                $this->entityManager->flush();
            }
        } elseif ($message instanceof DeleteUpstreamReportMessage) {
            $this->logger->error('A withdrawn report could not be deleted at the collector; it stays in the failed transport (messenger:failed:retry)', ['upstream_id' => $message->upstreamId]);
        }
    }
}
