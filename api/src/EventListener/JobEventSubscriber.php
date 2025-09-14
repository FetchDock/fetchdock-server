<?php

namespace App\EventListener;

use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alternative implementation using EventSubscriberInterface.
 * This demonstrates how workers' events can be picked up by subscribers.
 */
class JobEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents()
    {
        return [
            JobPickedUpEvent::class => 'onJobPickedUp',
            JobUpdateEvent::class => 'onJobUpdate',
            JobCompletedEvent::class => 'onJobCompleted',
            JobFailedEvent::class => 'onJobFailed',
        ];
    }

    public function onJobPickedUp(JobPickedUpEvent $event): void
    {
        $this->logger->info('Job picked up by worker (subscriber)', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'worker_identifier' => $event->getWorkerIdentifier(),
            'event' => 'job.picked_up',
            'source' => 'subscriber'
        ]);
    }

    public function onJobUpdate(JobUpdateEvent $event): void
    {
        $this->logger->info('Job update occurred (subscriber)', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'update_message' => $event->getUpdateMessage(),
            'context' => $event->getContext(),
            'event' => 'job.update',
            'source' => 'subscriber'
        ]);
    }

    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $this->logger->info('Job completed successfully (subscriber)', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'metadata' => $event->getMetadata(),
            'event' => 'job.completed',
            'source' => 'subscriber'
        ]);
    }

    public function onJobFailed(JobFailedEvent $event): void
    {
        $this->logger->error('Job failed (subscriber)', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'exception_message' => $event->getException()->getMessage(),
            'context' => $event->getContext(),
            'event' => 'job.failed',
            'source' => 'subscriber'
        ]);
    }
}