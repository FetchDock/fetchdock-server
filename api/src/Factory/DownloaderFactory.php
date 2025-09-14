<?php

namespace App\Factory;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class DownloaderFactory
{
    /**
     * @var iterable <\App\Service\Downloader\DownloaderInterface>
     */
    private iterable $downloaders;

    public function __construct(
        #[AutoWireIterator('app.downloader')]
        iterable $downloaders,
        private LoggerInterface $logger,
    )
    {
        // Reindex the iterable to an array to avoid multiple iterations over the generator.
        foreach ($downloaders as $downloader) {
            /** @var \App\Service\Downloader\DownloaderInterface $downloader */
            $this->downloaders[$downloader->getIdentifier()] = $downloader;
        }
    }

    /**
     * @return iterable<\App\Service\Downloader\DownloaderInterface>
     */
    public function getEnabledDownloaders(): iterable
    {
        // For now, all downloaders are considered enabled.
        return $this->downloaders;
    }

    public function getDownloaderByIdentifier(string $identifier): ?\App\Service\Downloader\DownloaderInterface
    {
        return $this->downloaders[$identifier] ?? null;
    }

    public function isValidDownloader(string $identifier): bool
    {
        return isset($this->downloaders[$identifier]);
    }

    /**
     * @param UriInterface $uri
     * @return iterable<\App\Service\Downloader\DownloaderInterface>
     */
    public function getDownloadersByUri(UriInterface $uri): iterable
    {
        $this->logger->debug('Looking for downloaders supporting URI', ['uri' => $uri]);
        foreach ($this->downloaders as $downloader) {
            $this->logger->debug('Checking downloader for URI support', [
                'downloader' => $downloader->getIdentifier(),
                'uri' => $uri
            ]);
            /** @var \App\Service\Downloader\DownloaderInterface $downloader */
            if ($downloader->supportsUri($uri)) {
                yield $downloader;
            }
        }
    }
}
