<?php

namespace App\EventListener;

use App\Event\JobCompletedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class CleanupListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    )
    {
    }

    #[AsEventListener(event: JobCompletedEvent::class)]
    public function onJobCompleted(JobCompletedEvent $event): void
    {
        // downloadJob has completed, there is no need for the cookies anymore,
        // and for privacy reasons, we MUST remove it from the database

        $downloadJob = $event->getDownloadJob();
        $downloadJob->setCookies(null);
        $this->entityManager->persist($downloadJob);
        $this->logger->info('Cleaned up cookies for completed job', [
            'job_id' => $downloadJob->getId(),
            'uri' => $downloadJob->getUri(),
        ]);
        $this->entityManager->flush();
    }
}
