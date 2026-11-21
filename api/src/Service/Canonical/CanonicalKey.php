<?php

namespace App\Service\Canonical;

/**
 * The canonical key of a URL, with how it was derived (for logging and debugging rules).
 */
final readonly class CanonicalKey
{
    public const string LAYER_YT_DLP = 'ytdlp';
    public const string LAYER_GALLERY_DL = 'gdl';
    public const string LAYER_URL = 'url';

    public function __construct(
        /** e.g. "ytdlp:Youtube:dQw4w9WgXcQ", "gdl:imgur:gallery:abcde", "url:https://example.com/a?b=1" */
        public string $key,
        public string $layer,
        /** Name of the canonicalization profile used */
        public string $profile,
    ) {
    }
}
