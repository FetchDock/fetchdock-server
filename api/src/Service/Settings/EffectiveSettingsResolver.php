<?php

namespace App\Service\Settings;

use App\Entity\Client;
use App\Entity\OidcSubjectIdentifier;
use App\Entity\SettingsLayerInterface;
use App\Entity\Site;
use App\Entity\UserDownloaderSetting;
use App\Factory\DownloaderFactory;
use App\Model\EffectiveSettings;
use App\Repository\UserDownloaderSettingRepository;
use App\Repository\UserSettingRepository;
use App\Repository\UserSiteSettingRepository;

/**
 * Merges the settings layers into the settings that apply to a download: hard defaults, then each
 * layer in order, where a non-null value overrides the previous layers:
 *
 *   SiteSetting → UserSetting → UserDownloaderSetting → UserSiteSetting → ClientSetting
 *
 * UserDownloaderSetting only affects the downloader choice: disabled downloaders are never chosen,
 * and without a preferred downloader the one with the lowest priority (then server order) wins.
 */
class EffectiveSettingsResolver
{
    public const bool DEFAULT_SEND_COOKIES = true;
    public const bool DEFAULT_SEND_USER_AGENT = true;
    public const bool DEFAULT_SEND_REFERRER = false;

    public function __construct(
        private readonly DownloaderFactory $downloaderFactory,
        private readonly UserSettingRepository $userSettingRepository,
        private readonly UserDownloaderSettingRepository $userDownloaderSettingRepository,
        private readonly UserSiteSettingRepository $userSiteSettingRepository,
    ) {
    }

    /**
     * @param list<string> $availableDownloaders downloaders that can handle the URL, empty if none
     * @param Client|null  $client               the requesting client; must belong to $owner
     */
    public function resolve(?Site $site, array $availableDownloaders, ?OidcSubjectIdentifier $owner = null, ?Client $client = null): EffectiveSettings
    {
        if (null !== $client && $client->getOwner() !== $owner) {
            $client = null;
        }

        /** @var list<SettingsLayerInterface> $layers */
        $layers = array_values(array_filter([
            $site?->getDefaultSetting(),
            null !== $owner ? $this->userSettingRepository->findOneBy(['owner' => $owner]) : null,
            null !== $owner && null !== $site?->getId() ? $this->userSiteSettingRepository->findOneBy(['owner' => $owner, 'site' => $site]) : null,
            $client?->getSetting(),
        ]));

        $sendCookies = self::DEFAULT_SEND_COOKIES;
        $sendUserAgent = self::DEFAULT_SEND_USER_AGENT;
        $sendReferrer = self::DEFAULT_SEND_REFERRER;
        $preferredDownloader = null;

        $candidates = $this->orderedCandidates($availableDownloaders, $this->downloaderSettings($owner));

        foreach ($layers as $layer) {
            $sendCookies = $layer->isSendCookies() ?? $sendCookies;
            $sendUserAgent = $layer->isSendUserAgent() ?? $sendUserAgent;
            $sendReferrer = $layer->isSendReferrer() ?? $sendReferrer;
            if (in_array($layer->getPreferredDownloader(), $candidates, true)) {
                $preferredDownloader = $layer->getPreferredDownloader();
            }
        }

        return new EffectiveSettings(
            sendCookies: $sendCookies,
            sendUserAgent: $sendUserAgent,
            sendReferrer: $sendReferrer,
            downloader: $preferredDownloader ?? $candidates[0] ?? null,
        );
    }

    /**
     * Whether the owner hasn't disabled the downloader (and the server knows it).
     */
    public function isDownloaderEnabled(string $downloader, ?OidcSubjectIdentifier $owner): bool
    {
        return [] !== $this->orderedCandidates([$downloader], $this->downloaderSettings($owner));
    }

    /**
     * @return array<string, UserDownloaderSetting>
     */
    private function downloaderSettings(?OidcSubjectIdentifier $owner): array
    {
        if (null === $owner) {
            return [];
        }

        $settings = [];
        foreach ($this->userDownloaderSettingRepository->findBy(['owner' => $owner]) as $setting) {
            $settings[$setting->getDownloader()] = $setting;
        }

        return $settings;
    }

    /**
     * The enabled downloaders able to handle the URL, by user priority, then DownloaderFactory order.
     *
     * @param list<string>                         $availableDownloaders
     * @param array<string, UserDownloaderSetting> $downloaderSettings
     *
     * @return list<string>
     */
    private function orderedCandidates(array $availableDownloaders, array $downloaderSettings): array
    {
        $candidates = [];
        $index = 0;
        foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
            $identifier = $downloader->getIdentifier();
            $setting = $downloaderSettings[$identifier] ?? null;
            if (in_array($identifier, $availableDownloaders, true) && false !== $setting?->isEnabled()) {
                $candidates[] = [$setting?->getPriority() ?? PHP_INT_MAX, $index, $identifier];
            }
            ++$index;
        }
        sort($candidates);

        return array_column($candidates, 2);
    }
}
