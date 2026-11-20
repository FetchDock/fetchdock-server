<?php

namespace App\Model;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The settings that apply to a download, after merging all settings layers.
 */
final readonly class EffectiveSettings
{
    public function __construct(
        public bool $sendCookies,
        public bool $sendUserAgent,
        public bool $sendReferrer,
        /** Downloader identifier, or null when no downloader supports the URL */
        public ?string $downloader,
        /** Command line options of the downloader (see GET /downloaders/{id}/options-schema) */
        #[ApiProperty(openapiContext: ['type' => 'object', 'additionalProperties' => true])]
        public \ArrayObject $downloaderOptions = new \ArrayObject(),
    ) {
    }
}
