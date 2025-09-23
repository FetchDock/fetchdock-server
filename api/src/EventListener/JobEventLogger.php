<?php

namespace App\EventListener;

use App\Entity\DownloadJobEvent;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Example event listener that logs job events.
 * This demonstrates how workers' events can be picked up and handled.
 */
readonly class JobEventLogger
{
    public function __construct(
        private LoggerInterface        $logger,
        private EntityManagerInterface $entityManager
    )
    {
    }

    #[AsEventListener(event: JobPickedUpEvent::class)]
    public function onJobPickedUp(JobPickedUpEvent $event): void
    {
        $jobEvent = new DownloadJobEvent()
            ->setDownloadJob($event->getDownloadJob())
            ->setEvent('job.picked_up')
            ->setSource('listener');

        $this->logger->info('Job picked up by worker', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'worker_identifier' => $event->getWorkerIdentifier(),
            'event' => $jobEvent->getEvent()
        ]);

        $this->storeDownloadJobEvent($jobEvent);
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

        $jobEvent = new DownloadJobEvent()
            ->setDownloadJob($event->getDownloadJob())
            ->setEvent('job.update')
            ->setSource('listener')
            ->setUpdateMessage($event->getUpdateMessage())
            ->setContext($event->getContext() ?: null);


        $this->storeDownloadJobEvent($jobEvent);
    }

    #[AsEventListener(event: JobCompletedEvent::class)]
    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $jobEvent = new DownloadJobEvent()
            ->setDownloadJob($event->getDownloadJob())
            ->setEvent('job.completed')
            ->setSource('listener')
            ->setMetadata($event->getMetadata() ?: null);

        $this->logger->info('Job completed successfully', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'metadata' => $event->getMetadata(),
            'event' => 'job.completed'
        ]);

        $this->storeDownloadJobEvent($jobEvent);
    }

    #[AsEventListener(event: JobFailedEvent::class)]
    public function onJobFailed(JobFailedEvent $event): void
    {
        $jobEvent = new DownloadJobEvent()
            ->setDownloadJob($event->getDownloadJob())
            ->setEvent('job.failed')
            ->setSource('listener')
            ->setUpdateMessage($event->getException()->getMessage())
            ->setContext($event->getContext() ?: null)
            ->setMetadata($event->getException() ? [
                'exception_message' => $event->getException()->getMessage(),
                'exception_trace' => $event->getException()->getTraceAsString()
            ] : null);

        $this->logger->error('Job failed', [
            'job_id' => $event->getDownloadJob()->getId(),
            'uri' => $event->getDownloadJob()->getUri(),
            'exception_message' => $event->getException()->getMessage(),
            'context' => $event->getContext(),
            'event' => 'job.failed'
        ]);

        $this->storeDownloadJobEvent($jobEvent);
    }

    private function storeDownloadJobEvent(DownloadJobEvent $jobEvent): void
    {
        $this->entityManager->persist($jobEvent->getDownloadJob());
        $this->entityManager->persist($jobEvent);
        $this->entityManager->flush();
    }
}
