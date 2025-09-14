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
use GuzzleHttp\Psr7\Uri;
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
        private EventDispatcherInterface $eventDispatcher
    )
    {
    }

    public function __invoke(
        DownloadJob $downloadJob
    )
    {
        try {
            // Dispatch job picked up event
            $this->eventDispatcher->dispatch(new JobPickedUpEvent($downloadJob));

            $this->logger->info('Processing download job', [
                'downloadJobId' => $downloadJob->getId(),
                'uri' => $downloadJob->getUri()
            ]);
            
            // Check if downloader is set, if so, get the downloader by identifier
            if ($downloadJob->getDownloader()) {
                $downloader = $this->getDownloaderByDownloaderIdentifier($downloadJob->getDownloader());
                if (!$downloader) {
                    throw new \InvalidArgumentException('Invalid downloader specified: ' . $downloadJob->getDownloader());
                }
            } else {
                // Get downloader by URI
                $downloaders = $this->getDownloaderByUri($downloadJob->getUri());
                $downloader = null;
                foreach ($downloaders as $d) {
                    $downloader = $d;
                    break; // Get the first one
                }
                if (!$downloader) {
                    throw new \InvalidArgumentException('No downloader found for URI: ' . $downloadJob->getUri());
                }

                // Set the downloader identifier to the download job and persist it
                $downloadJob->setDownloader($downloader->getIdentifier());
                $downloadJob->setState(DownloadStateEnum::IN_PROGRESS);
                $this->entityManager->persist($downloadJob);
                $this->entityManager->flush();

                // Dispatch job update event when downloader is selected and state changes
                $this->eventDispatcher->dispatch(new JobUpdateEvent(
                    $downloadJob,
                    'Downloader selected and job state updated to IN_PROGRESS',
                    ['downloader' => $downloader->getIdentifier()]
                ));
            }

            $this->logger->info('Using downloader', ['downloader' => $downloader->getIdentifier()]);

            // Now we have a valid downloader, we can proceed with the download
            $downloader->download($downloadJob);

            $downloadJob->setState(DownloadStateEnum::COMPLETED);
            $this->entityManager->persist($downloadJob);
            $this->entityManager->flush();
            
            $this->logger->info('Download job completed', ['downloadJobId' => $downloadJob->getId()]);

            // Dispatch job completed event
            $this->eventDispatcher->dispatch(new JobCompletedEvent($downloadJob));
        } catch (\Throwable $exception) {
            // Update job state to failed
            $downloadJob->setState(DownloadStateEnum::FAILED);
            $this->entityManager->persist($downloadJob);
            $this->entityManager->flush();

            // Dispatch job failed event
            $this->eventDispatcher->dispatch(new JobFailedEvent($downloadJob, $exception));

            // Re-throw the exception to maintain existing error handling behavior
            throw $exception;
        }
    }

    private function getDownloaderByUri(string $uri)
    {
        return $this->downloaderFactory->getDownloadersByUri(new Uri($uri));
    }

    private function getDownloaderByDownloaderIdentifier(string $identifier)
    {
        return $this->downloaderFactory->getDownloaderByIdentifier($identifier);
    }
}
