<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Factory\DownloaderFactory;
use App\Resource\Version;
use Psr\Log\LoggerInterface;

class VersionProvider implements ProviderInterface
{
    public function __construct(
        private readonly DownloaderFactory $downloaderFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $downloaders = [];
            foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
                try {
                    $currentVersion = $downloader->getCurrentVersion();
                    $downloaders[] = new Version(
                        id: $downloader->getIdentifier(),
                        version: $currentVersion,
                        currentVersion: $currentVersion,
                        latestVersion: $downloader->getLatestVersion(),
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to get version info', [
                        'downloader' => $downloader->getIdentifier(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $downloaders;
        }

        if (isset($uriVariables['id'])) {
            $downloader = $this->downloaderFactory->getDownloaderByIdentifier($uriVariables['id']);
            if ($downloader) {
                try {
                    $currentVersion = $downloader->getCurrentVersion();

                    return new Version(
                        id: $downloader->getIdentifier(),
                        version: $currentVersion,
                        currentVersion: $currentVersion,
                        latestVersion: $downloader->getLatestVersion(),
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to get version info', [
                        'downloader' => $downloader->getIdentifier(),
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            }
        }

        return null;
    }
}
