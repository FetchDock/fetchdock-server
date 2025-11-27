<?php

namespace App\Service\Downloader;

interface CliDownloaderInterface extends DownloaderInterface
{
    /**
     * Get the command arguments array for updating this downloader via pip.
     *
     * This method should return an array of command arguments suitable for use
     * with Symfony's Process component or similar, for updating the downloader
     * (e.g., ['pip', 'install', '--upgrade', 'package-name']).
     *
     * @return array Command arguments for updating the downloader.
     */
    public function getUpdateCommandArgs(): array;
}
