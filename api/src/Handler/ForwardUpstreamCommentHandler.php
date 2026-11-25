<?php

namespace App\Handler;

use App\Entity\FailureReportUpstreamComment;
use App\Enum\UpstreamCommentState;
use App\Message\ForwardUpstreamCommentMessage;
use App\Service\FailureReport\CollectorClient;
use App\Service\FailureReport\CollectorException;
use App\Service\FailureReport\CollectorInstance;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Sends an admin's comment on a forwarded report to the collector (POST /v1/reports/{id}/comments). Comments go out
 * even while forwarding is disabled: the report is there already.
 */
#[AsMessageHandler]
final readonly class ForwardUpstreamCommentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CollectorClient $client,
        private CollectorInstance $instance,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ForwardUpstreamCommentMessage $message): void
    {
        $comment = $this->entityManager->find(FailureReportUpstreamComment::class, Uuid::fromString($message->commentId));
        if (null === $comment || UpstreamCommentState::PENDING !== $comment->getState()) {
            return;
        }

        $reportId = $comment->getReport()->getUpstreamId();
        if (null === $reportId) {
            $comment->markFailed('The report was not forwarded.');
            $this->entityManager->flush();

            return;
        }

        try {
            $upstreamId = $this->instance->call(fn (string $secret): Uuid => $this->client->addComment($secret, $reportId, $comment->getComment(), $comment->getCreatedAt()));
        } catch (CollectorException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $comment->markFailed($e->isNotFound() ? 'The collector no longer has this report.' : $e->getMessage());
            $this->entityManager->flush();
            $this->logger->warning('The collector refused a report comment', ['comment' => $message->commentId, 'reason' => $e->getMessage()]);

            return;
        }

        $comment->markForwarded($upstreamId);
        $this->entityManager->flush();
    }
}
