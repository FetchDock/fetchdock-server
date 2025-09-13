<?php

namespace App\Handler;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Factory\DownloaderFactory;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DownloadJobHandler
{
    public function __construct(
        private(set) EntityManagerInterface $entityManager,
        private(set) DownloaderFactory $downloaderFactory,
        private LoggerInterface $logger
    )
    {
    }

    public function __invoke(
        DownloadJob $downloadJob
    )
    {
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
        }

        $this->logger->info('Using downloader', ['downloader' => $downloader->getIdentifier()]);

        // Now we have a valid downloader, we can proceed with the download
        $downloader->download($downloadJob);

        $downloadJob->setState(DownloadStateEnum::COMPLETED);
        $this->entityManager->persist($downloadJob);
        $this->entityManager->flush();
        $this->logger->info('Download job completed', ['downloadJobId' => $downloadJob->getId()]);
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
