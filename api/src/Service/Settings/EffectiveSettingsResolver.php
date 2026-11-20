<?php

namespace App\Service\Settings;

use App\Entity\AbstractSiteSetting;
use App\Entity\Site;
use App\Factory\DownloaderFactory;
use App\Model\EffectiveSettings;

/**
 * Merges the settings layers into the settings that apply to a download: hard defaults, then each
 * layer in order, where a non-null value overrides the previous layers.
 *
 * Phase 1 (#23): hard defaults → site defaults (SiteSetting). User layers follow in phase 2 (#21).
 */
readonly class EffectiveSettingsResolver
{
    public const bool DEFAULT_SEND_COOKIES = true;
    public const bool DEFAULT_SEND_USER_AGENT = true;
    public const bool DEFAULT_SEND_REFERRER = false;

    public function __construct(
        private DownloaderFactory $downloaderFactory,
    ) {
    }

    /**
     * @param list<string> $availableDownloaders downloaders that can handle the URL, empty if none
     */
    public function resolve(?Site $site, array $availableDownloaders): EffectiveSettings
    {
        /** @var list<AbstractSiteSetting> $layers */
        $layers = array_values(array_filter([$site?->getDefaultSetting()]));

        $sendCookies = self::DEFAULT_SEND_COOKIES;
        $sendUserAgent = self::DEFAULT_SEND_USER_AGENT;
        $sendReferrer = self::DEFAULT_SEND_REFERRER;
        $downloader = null;

        foreach ($layers as $layer) {
            $sendCookies = $layer->isSendCookies() ?? $sendCookies;
            $sendUserAgent = $layer->isSendUserAgent() ?? $sendUserAgent;
            $sendReferrer = $layer->isSendReferrer() ?? $sendReferrer;
            if (null !== $layer->getDownloader() && in_array($layer->getDownloader(), $availableDownloaders, true)) {
                $downloader = $layer->getDownloader();
            }
        }

        return new EffectiveSettings(
            sendCookies: $sendCookies,
            sendUserAgent: $sendUserAgent,
            sendReferrer: $sendReferrer,
            downloader: $downloader ?? $this->defaultDownloader($availableDownloaders),
        );
    }

    /**
     * The first enabled downloader (in DownloaderFactory order) that can handle the URL.
     *
     * @param list<string> $availableDownloaders
     */
    private function defaultDownloader(array $availableDownloaders): ?string
    {
        foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
            if (in_array($downloader->getIdentifier(), $availableDownloaders, true)) {
                return $downloader->getIdentifier();
            }
        }

        return null;
    }
}
