<?php

namespace App\Handler;

use App\Message\DeleteUpstreamReportMessage;
use App\Service\FailureReport\CollectorClient;
use App\Service\FailureReport\CollectorException;
use App\Service\FailureReport\CollectorInstance;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a withdrawn report at the collector (DELETE /v1/reports/{id}); 204 and 404 both confirm. Runs whether or
 * not forwarding is still enabled. An unreachable collector throws, so Messenger retries.
 */
#[AsMessageHandler]
final readonly class DeleteUpstreamReportHandler
{
    public function __construct(
        private CollectorClient $client,
        private CollectorInstance $instance,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteUpstreamReportMessage $message): void
    {
        if (rtrim($message->collectorUrl, '/') !== $this->client->getCollectorUrl()) {
            $this->logger->error('A withdrawn report can\'t be deleted: it went to another collector than the configured one', [
                'upstream_id' => $message->upstreamId,
                'collector' => $message->collectorUrl,
            ]);

            return;
        }

        $upstreamId = Uuid::fromString($message->upstreamId);
        try {
            $this->instance->call(fn (string $secret) => $this->client->deleteReport($secret, $upstreamId));
        } catch (CollectorException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            // E.g. a blocked instance: retrying won't help, keep the message in the failed transport
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
        $this->logger->info('Withdrawn report deleted at the collector', ['upstream_id' => $message->upstreamId]);
    }
}
