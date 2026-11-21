<?php

namespace App\Dto;

use App\Entity\SiteSetting;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A site's admin-managed default settings (config/sites.yaml). Null = no site default, the value
 * comes from the previous layer (see App\Service\Settings\EffectiveSettingsResolver).
 */
final readonly class SiteDefaultsDTO
{
    public function __construct(
        #[Groups(['site:read'])]
        public ?bool $sendCookies = null,
        #[Groups(['site:read'])]
        public ?bool $sendUserAgent = null,
        #[Groups(['site:read'])]
        public ?bool $sendReferrer = null,
        /** Downloader identifier (see /downloaders) */
        #[Groups(['site:read'])]
        public ?string $downloader = null,
    ) {
    }

    public static function fromSetting(?SiteSetting $setting): self
    {
        return new self(
            $setting?->isSendCookies(),
            $setting?->isSendUserAgent(),
            $setting?->isSendReferrer(),
            $setting?->getDownloader(),
        );
    }
}
