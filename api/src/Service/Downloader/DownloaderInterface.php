<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
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
     */
    public function download(DownloadJobInterface $downloadJob): true;

    public function getDownloaderType(): DownloaderTypeEnum;

    /**
     * Get a list of supported domains by this downloader.
     *
     * @return array<UriInterface> List of supported domains (e.g. ["twitter.com", "instagram.com"])
     */
    public function getSupportedDomains(): array;

    /**
     * @param DownloadJobInterface $downloadJob
     * @return bool
     */
    public function supportsDownloadJob(DownloadJobInterface $downloadJob): bool;

    /**
     * Get the currently installed version of the downloader service.
     *
     * @return string Version string (e.g. "2024.06.1")
     */
    public function getCurrentVersion(): string;

    /**
     * Get the latest available version of the downloader service.
     *
     * @return string Version string (e.g. "2024.06.1")
     */
    public function getLatestVersion(): string;
}
