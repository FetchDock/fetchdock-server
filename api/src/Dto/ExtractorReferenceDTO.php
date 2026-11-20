<?php

namespace App\Dto;

final readonly class ExtractorReferenceDTO
{
    public function __construct(
        /** Downloader identifier, e.g. "gallery-dl-cli" */
        public string $downloader,
        /** The downloader's extractor key, e.g. "twitter" */
        public string $key,
    ) {
    }
}
