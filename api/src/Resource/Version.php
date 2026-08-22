<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\VersionProvider;

#[ApiResource(
    shortName: 'Downloader versions',
    description: 'Information about the current and latest versions of available downloaders.',
    operations: [
        new Get(
            uriTemplate: '/versions/{id}',
        ),
        new GetCollection(
            uriTemplate: '/versions',
        ),
    ],
    provider: VersionProvider::class
)]
class Version
{
    public function __construct(
        public string $id,
        #[ApiProperty(deprecationReason: 'Deprecated: use currentVersion and latestVersion instead. The version field will be removed after 2025-06-01. See API documentation for migration details.')]
        public string $version,
        public string $currentVersion,
        public string $latestVersion,
    ) {
    }
}
