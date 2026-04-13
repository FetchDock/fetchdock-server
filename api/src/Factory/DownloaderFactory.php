<?php

namespace App\Factory;

use App\Model\DownloadJobInterface;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\DownloaderInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class DownloaderFactory
{
    /**
     * @var iterable <DownloaderInterface>
     */
    private iterable $downloaders;

    public function __construct(
        #[AutowireIterator('app.downloader')]
        iterable $downloaders,
        private LoggerInterface $logger,
    ) {
        // Reindex the iterable to an array to avoid multiple iterations over the generator.
        foreach ($downloaders as $downloader) {
            /* @var DownloaderInterface $downloader */
            $this->downloaders[$downloader->getIdentifier()] = $downloader;
        }
    }

    /**
     * @return iterable<DownloaderInterface>
     */
    public function getEnabledDownloaders(): iterable
    {
        // For now, all downloaders are considered enabled.
        return $this->downloaders;
    }

    /**
     * @return iterable<CliDownloaderInterface>
     */
    public function getCliDownloaders(): iterable
    {
        foreach ($this->downloaders as $downloader) {
            if ($downloader instanceof CliDownloaderInterface) {
                yield $downloader;
            }
        }
    }

    public function getDownloaderByIdentifier(string $identifier): ?DownloaderInterface
    {
        return $this->downloaders[$identifier] ?? null;
    }

    public function isValidDownloader(string $identifier): bool
    {
        return isset($this->downloaders[$identifier]);
    }

    /**
     * @deprecated Use getDownloadersByDownloadJob instead.
     * @return iterable<DownloaderInterface>
     */
    public function getDownloadersByUri(UriInterface $uri): iterable
    {
        $this->logger->debug('Looking for downloaders supporting URI', ['uri' => $uri]);
        foreach ($this->downloaders as $downloader) {
            $this->logger->debug('Checking downloader for URI support', [
                'downloader' => $downloader->getIdentifier(),
                'uri' => $uri,
            ]);
            /** @var DownloaderInterface $downloader */
            if ($downloader->supportsUri($uri)) {
                yield $downloader;
            }
        }
    }

    public function getDownloadersByDownloadJob(DownloadJobInterface $downloadJob): iterable
    {
        $this->logger->debug('Looking for download jobs supporting download job', [
            'uri' => $downloadJob->getUrl()
        ]);
        /** @var DownloaderInterface $downloader */
        foreach ($this->downloaders as $downloader) {
            $this->logger->debug('Checking download job for URI support', [
                'downloader' => $downloader->getIdentifier(),
                'uri' => $downloadJob->getUrl(),
            ]);
            if ($downloader->supportsDownloadJob($downloadJob)) {
                yield $downloader;
            }
        }
    }
}
