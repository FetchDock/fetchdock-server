<?php

namespace App\Service\Settings;

use App\Entity\Client;
use App\Entity\OidcSubjectIdentifier;
use App\Entity\SettingsLayerInterface;
use App\Entity\Site;
use App\Entity\UserDownloaderSetting;
use App\Entity\UserSiteSetting;
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
 * UserDownloaderSetting affects the downloader choice: disabled downloaders are never chosen,
 * and without a preferred downloader the one with the lowest priority (then server order) wins.
 *
 * Downloader options of the chosen downloader: UserDownloaderSetting.options, then per option
 * UserSiteSetting.downloaderOptions[downloader].
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

        $userSiteSetting = $this->userSiteSetting($site, $owner);

        /** @var list<SettingsLayerInterface> $layers */
        $layers = array_values(array_filter([
            $site?->getDefaultSetting(),
            null !== $owner ? $this->userSettingRepository->findOneBy(['owner' => $owner]) : null,
            $userSiteSetting,
            $client?->getSetting(),
        ]));

        $sendCookies = self::DEFAULT_SEND_COOKIES;
        $sendUserAgent = self::DEFAULT_SEND_USER_AGENT;
        $sendReferrer = self::DEFAULT_SEND_REFERRER;
        $preferredDownloader = null;

        $downloaderSettings = $this->downloaderSettings($owner);
        $candidates = $this->orderedCandidates($availableDownloaders, $downloaderSettings);

        foreach ($layers as $layer) {
            $sendCookies = $layer->isSendCookies() ?? $sendCookies;
            $sendUserAgent = $layer->isSendUserAgent() ?? $sendUserAgent;
            $sendReferrer = $layer->isSendReferrer() ?? $sendReferrer;
            if (in_array($layer->getPreferredDownloader(), $candidates, true)) {
                $preferredDownloader = $layer->getPreferredDownloader();
            }
        }

        $downloader = $preferredDownloader ?? $candidates[0] ?? null;

        return new EffectiveSettings(
            sendCookies: $sendCookies,
            sendUserAgent: $sendUserAgent,
            sendReferrer: $sendReferrer,
            downloader: $downloader,
            downloaderOptions: new \ArrayObject(null !== $downloader ? self::mergeOptions($downloader, $downloaderSettings, $userSiteSetting) : []),
        );
    }

    /**
     * The downloader options for a download: the owner's options for the downloader, overridden
     * per option by the owner's options for the site and downloader.
     *
     * @return array<string, mixed>
     */
    public function resolveDownloaderOptions(?Site $site, string $downloader, ?OidcSubjectIdentifier $owner): array
    {
        if (null === $owner) {
            return [];
        }

        return self::mergeOptions($downloader, $this->downloaderSettings($owner), $this->userSiteSetting($site, $owner));
    }

    /**
     * @param array<string, UserDownloaderSetting> $downloaderSettings
     *
     * @return array<string, mixed>
     */
    private static function mergeOptions(string $downloader, array $downloaderSettings, ?UserSiteSetting $userSiteSetting): array
    {
        $siteOptions = $userSiteSetting?->getDownloaderOptions()[$downloader] ?? [];

        return array_replace(($downloaderSettings[$downloader] ?? null)?->getOptions() ?? [], is_array($siteOptions) ? $siteOptions : []);
    }

    private function userSiteSetting(?Site $site, ?OidcSubjectIdentifier $owner): ?UserSiteSetting
    {
        if (null === $owner || null === $site?->getId()) {
            return null;
        }

        return $this->userSiteSettingRepository->findOneBy(['owner' => $owner, 'site' => $site]);
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
