<?php

namespace App\Handler;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use App\Factory\DownloaderFactory;
use App\Model\DownloadJobInterface;
use App\Service\Downloader\DownloaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DownloadJobHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DownloaderFactory $downloaderFactory,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        DownloadJob $downloadJob,
    ): void {
        // $downloadJob is the message's copy of the job, not managed by Doctrine: only use the stored job below
        $downloadJobEntity = null;
        try {
            // Load the DownloadJob from the database to ensure we have the latest state
            $downloadJobEntity = $this->entityManager->getRepository(DownloadJob::class)->find($downloadJob->getId());
            if (!$downloadJobEntity) {
                throw new EntityNotFoundException('DownloadJob not found with ID: '.$downloadJob->getId());
            }

            // Dispatch job picked up event
            $this->eventDispatcher->dispatch(new JobPickedUpEvent($downloadJobEntity));

            // Check if downloader is set, if so, get the downloader by identifier
            if ($downloadJobEntity->getDownloader()) {
                $downloader = $this->getDownloaderByDownloaderIdentifier($downloadJobEntity->getDownloader());
                if (!$downloader) {
                    throw new \InvalidArgumentException('Invalid downloader specified: '.$downloadJobEntity->getDownloader());
                }
                $this->eventDispatcher->dispatch(new JobUpdateEvent(
                    $downloadJobEntity,
                    'Using specified downloader: '.$downloader->getIdentifier(),
                    ['downloader' => $downloader->getIdentifier()]
                ));
            } else {
                // Get downloader by URI
                $downloaders = $this->getDownloaderByDownloadJob($downloadJobEntity);
                $downloader = null;
                foreach ($downloaders as $d) {
                    $downloader = $d;
                    break; // Get the first one
                }
                if (!$downloader) {
                    throw new \InvalidArgumentException('No downloader found for URI: '.$downloadJobEntity->getUri());
                }

                // Set the downloader identifier to the download job and persist it
                $downloadJobEntity->setDownloader($downloader->getIdentifier());
                $downloadJobEntity->setState(DownloadStateEnum::IN_PROGRESS);
                $this->entityManager->persist($downloadJobEntity);
                $this->entityManager->flush();

                // Dispatch job update event when downloader is selected and state changes
                $this->eventDispatcher->dispatch(new JobUpdateEvent(
                    $downloadJobEntity,
                    'Downloader selected and job state updated to IN_PROGRESS',
                    ['downloader' => $downloader->getIdentifier()]
                ));
            }

            $this->logger->info('Using downloader', ['downloader' => $downloader->getIdentifier()]);

            // Now we have a valid downloader, we can proceed with the download
            $downloader->download($downloadJobEntity);

            $downloadJobEntity->setState(DownloadStateEnum::COMPLETED);
            $this->entityManager->persist($downloadJobEntity);
            $this->entityManager->flush();

            $this->logger->info('Download job completed', ['downloadJobId' => $downloadJobEntity->getId()]);

            // Dispatch job completed event
            $this->eventDispatcher->dispatch(new JobCompletedEvent($downloadJobEntity));
        } catch (\Throwable $exception) {
            $this->recordFailure($downloadJob, $downloadJobEntity, $exception);

            // Re-throw the original exception, so messenger retries and logs the actual cause
            throw $exception;
        }
    }

    /**
     * Marks the job as failed and dispatches JobFailedEvent. Failures while doing so are logged, never thrown: they
     * would replace the original exception (e.g. a closed entity manager after a database error).
     */
    private function recordFailure(DownloadJob $message, ?DownloadJob $downloadJobEntity, \Throwable $exception): void
    {
        $this->logger->error('Download job failed', [
            'job_id' => $message->getId(),
            'job_uuid' => $message->getUuid()?->toRfc4122(),
            'exception' => $exception,
        ]);

        try {
            // Reload the stored job for its latest state. Throws when the entity manager was closed by a database
            // error; that is logged below.
            $stored = null;
            if ($downloadJobEntity instanceof DownloadJob) {
                $stored = $this->entityManager->getRepository(DownloadJob::class)->find($downloadJobEntity->getId());
            } elseif (null !== $message->getId()) {
                $stored = $this->entityManager->getRepository(DownloadJob::class)->find($message->getId());
            }
            if ($stored instanceof DownloadJob) {
                $stored->setState(DownloadStateEnum::FAILED);
                $this->entityManager->persist($stored);
                $this->entityManager->flush();
            }

            // The stored job when there is one, so listeners never persist the message's copy
            $this->eventDispatcher->dispatch(new JobFailedEvent($stored ?? $downloadJobEntity ?? $message, $exception));
        } catch (\Throwable $bookkeepingError) {
            $this->logger->error('Unable to record the download job failure', [
                'job_id' => $message->getId(),
                'exception' => $bookkeepingError,
            ]);
        }
    }

    private function getDownloaderByDownloaderIdentifier(string $identifier): ?DownloaderInterface
    {
        return $this->downloaderFactory->getDownloaderByIdentifier($identifier);
    }

    private function getDownloaderByDownloadJob(DownloadJobInterface $downloadJob): iterable
    {
        return $this->downloaderFactory->getDownloadersByDownloadJob($downloadJob);
    }
}
