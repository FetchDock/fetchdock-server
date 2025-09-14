<?php

namespace App\EventListener;

use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Example event listener that logs job events.
 * This demonstrates how workers' events can be picked up and handled.
 */
class JobEventLogger
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[AsEventListener(event: JobPickedUpEvent::class)]
    public function onJobPickedUp(JobPickedUpEvent $event): void
    {
        $this->logger->info('Job picked up by worker', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'worker_identifier' => $event->getWorkerIdentifier(),
            'event' => 'job.picked_up'
        ]);
    }

    #[AsEventListener(event: JobUpdateEvent::class)]
    public function onJobUpdate(JobUpdateEvent $event): void
    {
        $this->logger->info('Job update occurred', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'update_message' => $event->getUpdateMessage(),
            'context' => $event->getContext(),
            'event' => 'job.update'
        ]);
    }

    #[AsEventListener(event: JobCompletedEvent::class)]
    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $this->logger->info('Job completed successfully', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'metadata' => $event->getMetadata(),
            'event' => 'job.completed'
        ]);
    }

    #[AsEventListener(event: JobFailedEvent::class)]
    public function onJobFailed(JobFailedEvent $event): void
    {
        $this->logger->error('Job failed', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'exception_message' => $event->getException()->getMessage(),
            'context' => $event->getContext(),
            'event' => 'job.failed'
        ]);
    }
}