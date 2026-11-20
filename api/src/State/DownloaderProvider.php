<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Factory\DownloaderFactory;
use App\Repository\SiteRepository;
use App\Resource\Downloader;
use App\Service\Downloader\DownloaderInterface;

class DownloaderProvider implements ProviderInterface
{
    public function __construct(
        private DownloaderFactory $downloaderFactory,
        private SiteRepository $siteRepository,
    ) {
    }

    /**
     * @return iterable<DownloaderInterface>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|object|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $downloaders = [];
            foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
                $downloaders[] = $this->createDownloaderModelFromDownloaderService($downloader);
            }

            return $downloaders;
        }

        if (isset($uriVariables['id'])) {
            $downloader = $this->downloaderFactory->getDownloaderByIdentifier($uriVariables['id']);
            if ($downloader) {
                return $this->createDownloaderModelFromDownloaderService($downloader);
            }
        }

        return null;
    }

    private function createDownloaderModelFromDownloaderService(DownloaderInterface $downloader): Downloader
    {
        $model = new Downloader();
        $model->id = $downloader->getIdentifier();
        $identifier = $model->id;
        $model->enabled = true; // For now, all downloaders are considered enabled.
        $model->downloaderType = $downloader->getDownloaderType();

        // Domains of the sites this downloader has extractors for (see app:sites:sync), plus the
        // domains the downloader reports itself.
        $supportedDomains = $this->siteRepository->findDomainsByDownloader($identifier);
        foreach ($downloader->getSupportedDomains() as $domain) {
            // A URL yields its host; anything else is taken as a host name.
            $supportedDomains[] = $this->getHostNameFromUrl((string) $domain) ?? (string) $domain;
        }
        $model->supportedDomains = array_values(array_unique($supportedDomains));

        return $model;
    }

    private function getHostNameFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
