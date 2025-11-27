<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Version;
use App\Enum\DownloaderTypeEnum;
use App\Factory\DownloaderFactory;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

readonly class VersionProvider implements ProviderInterface
{

    public function __construct(
        private(set) DownloaderFactory $downloaderFactory,
        private TagAwareCacheInterface $cache,
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $downloaders = [];
            foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
                $downloaders[] = new Version(
                    id: $downloader->getIdentifier(),
                    version: $downloader->getCurrentVersion(),
                    currentVersion: $downloader->getCurrentVersion(),
                    latestVersion: $downloader->getLatestVersion(),
                );
            }

            return $downloaders;
        }

        if (isset($uriVariables['id'])) {
            $downloader = $this->downloaderFactory->getDownloaderByIdentifier($uriVariables['id']);
            if ($downloader) {
                return new Version(
                    id: $downloader->getIdentifier(),
                    version: $downloader->getCurrentVersion(),
                    currentVersion: $downloader->getCurrentVersion(),
                    latestVersion: $downloader->getLatestVersion(),
                );
            }
        }

        return null;
    }
}
