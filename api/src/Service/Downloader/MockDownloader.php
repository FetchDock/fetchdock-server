<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Deprecated;
use Psr\Http\Message\UriInterface;

class MockDownloader implements DownloaderInterface
{
    public function getIdentifier(): string
    {
        return 'mock';
    }

    public function download(DownloadJobInterface $downloadJob): true
    {
        // Mock download implementation
        return true;
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function supportsUri(UriInterface $uri): bool
    {
        $host = $uri->getHost();
        return in_array($host, $this->getSupportedDomains(), true);
    }

    public function getSupportedDomains(): array
    {
        return ['example.com', 'test.com'];
    }

    public function getCurrentVersion(): string
    {
        return $this->getLatestVersion();
    }

    public function getLatestVersion(): string
    {
        return '1.0.0-mock';
    }
}
