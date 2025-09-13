<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use Psr\Http\Message\UriInterface;

class MockDownloader implements DownloaderInterface
{
    public function getIdentifier(): string
    {
        return 'mock';
    }

    public function download(UriInterface $uri): true
    {
        // Mock download implementation
        return true;
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function getSupportedDomains(): array
    {
        return ['example.com', 'test.com'];
    }

    public function supportsUri(UriInterface $uri): bool
    {
        $host = $uri->getHost();
        return in_array($host, $this->getSupportedDomains(), true);
    }
}
