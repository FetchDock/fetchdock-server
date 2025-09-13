<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use Psr\Http\Message\UriInterface;

interface DownloaderInterface
{
    /**
     * Get the unique identifier of this downloader.
     */
    public function getIdentifier(): string;

    /**
     * Download the given URI using the downloader service.
     * Only return true if the download request was successfully sent to the downloader service.
     * In any other case, throw an exception, which should be handled by the caller.
     *
     * @param UriInterface $uri
     * @return true
     */
    public function download(UriInterface $uri): true;

    public function getDownloaderType(): DownloaderTypeEnum;

    /**
     * Get a list of supported domains by this downloader.
     *
     * @return array<UriInterface> List of supported domains (e.g. ["twitter.com", "instagram.com"])
     */
    public function getSupportedDomains(): array;

    /**
     * Check if the given URI is supported by this downloader.
     *
     * @param UriInterface $uri
     * @return bool True if the URI is supported, false otherwise.
     */
    public function supportsUri(UriInterface $uri): bool;
}
