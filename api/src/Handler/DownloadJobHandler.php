<?php

namespace App\Handler;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use App\Factory\DownloaderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
class DownloadJobHandler
{
    public function __construct(
        private EntityManagerInterface   $entityManager,
        private DownloaderFactory        $downloaderFactory,
        private LoggerInterface          $logger,
        private EventDispatcherInterface $eventDispatcher
    )
    {
    }

    public function __invoke(
        DownloadJob $downloadJob
    )
    {
        try {
            // Load the DownloadJob from the database to ensure we have the latest state
            $downloadJobEntity = $this->entityManager->getRepository(DownloadJob::class)->find($downloadJob->getId());
            if (!$downloadJobEntity) {
                throw new EntityNotFoundException('DownloadJob not found with ID: ' . $downloadJob->getId());
            }

            // Dispatch job picked up event
            $this->eventDispatcher->dispatch(new JobPickedUpEvent($downloadJobEntity));

            // Check if downloader is set, if so, get the downloader by identifier
            if ($downloadJobEntity->getDownloader()) {
                $downloader = $this->getDownloaderByDownloaderIdentifier($downloadJobEntity->getDownloader());
                if (!$downloader) {
                    throw new InvalidArgumentException('Invalid downloader specified: ' . $downloadJobEntity->getDownloader());
                }
                $this->eventDispatcher->dispatch(new JobUpdateEvent(
                    $downloadJobEntity,
                    'Using specified downloader: ' . $downloader->getIdentifier(),
                    ['downloader' => $downloader->getIdentifier()]
                ));
            } else {
                // Get downloader by URI
                $downloaders = $this->getDownloaderByUri($downloadJobEntity->getUri());
                $downloader = null;
                foreach ($downloaders as $d) {
                    $downloader = $d;
                    break; // Get the first one
                }
                if (!$downloader) {
                    throw new InvalidArgumentException('No downloader found for URI: ' . $downloadJobEntity->getUri());
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
        } catch (EntityNotFoundException $e) {
            $this->logger->error('Download job not found', ['error' => $e->getMessage()]);
            $this->eventDispatcher->dispatch(new JobFailedEvent($downloadJob, $e));

            throw $e; // Re-throw to ensure the message is not lost
        } catch (Throwable $exception) {
            if($downloadJobEntity instanceof DownloadJob) {
                // Update job state to failed
                $downloadJobEntity->setState(DownloadStateEnum::FAILED);
                $this->entityManager->persist($downloadJobEntity);
                $this->entityManager->flush();
            }

            // Dispatch job failed event
            $this->eventDispatcher->dispatch(new JobFailedEvent($downloadJob, $exception));

            // Re-throw the exception to maintain existing error handling behavior
            throw $exception;
        }
    }

    private function getDownloaderByDownloaderIdentifier(string $identifier)
    {
        return $this->downloaderFactory->getDownloaderByIdentifier($identifier);
    }

    private function getDownloaderByUri(string $uri)
    {
        return $this->downloaderFactory->getDownloadersByUri(new Uri($uri));
    }
}
