<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\DownloaderTypeEnum;
use App\State\DownloaderProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/downloaders/{id}',
            provider: DownloaderProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/downloaders',
            provider: DownloaderProvider::class,
        ),
    ]
)]
class Downloader
{
    public string $id;

    public bool $enabled;

    public DownloaderTypeEnum $downloaderType;
    public array $supportedDomains = [];
}
