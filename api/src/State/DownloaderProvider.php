<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Downloader;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use Error;

class DownloaderProvider implements ProviderInterface
{
    public function __construct(
        private DownloaderFactory $downloaderFactory
    )
    {
    }

    /**
     * @return iterable<DownloaderInterface>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): null|array|object
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
        $model->enabled = true; // For now, all downloaders are considered enabled.
        $model->downloaderType = $downloader->getDownloaderType();

        $supportedDomains = [];
        foreach ($downloader->getSupportedDomains() as $domain) {
            try {
                // If the domain is a valid URL, extract the host.
                $supportedDomains[] = $this->getHostNameFromUrl($domain);
                continue;
            } catch (Error $e) {
                // Not a valid URL, treat as hostname.
                $supportedDomains[] = $domain;
            }
        }
        $model->supportedDomains = array_values(array_unique($supportedDomains));

        return $model;
    }

    private function getHostNameFromUrl(string $url): string
    {
        return parse_url($url, PHP_URL_HOST);
    }
}
