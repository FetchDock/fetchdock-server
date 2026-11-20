<?php

namespace App\Model;

/**
 * A downloader extractor that can handle a URL, as reported by bin/resolve_url.py.
 */
final readonly class ExtractorMatch
{
    public function __construct(
        /** Downloader identifier, e.g. "gallery-dl-cli" */
        public string $downloader,
        /** gallery-dl category or yt-dlp extractor key, e.g. "twitter" / "Twitter" */
        public string $key,
        /** Extractor family used to group unmapped extractors into one site, e.g. "twitter" */
        public string $family,
    ) {
    }
}
