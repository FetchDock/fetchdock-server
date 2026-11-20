<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\SiteRepository;
use App\Repository\UserSiteSettingRepository;
use App\Resource\Settings\UserSiteSettings;
use App\Security\CurrentOwner;

/**
 * @implements ProviderInterface<UserSiteSettings>
 */
final readonly class UserSiteSettingsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private SiteRepository $siteRepository,
        private UserSiteSettingRepository $userSiteSettingRepository,
    ) {
    }

    /**
     * @return UserSiteSettings|list<UserSiteSettings>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserSiteSettings|array|null
    {
        $owner = $this->currentOwner->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn ($setting) => UserSiteSettings::fromEntity($setting->getSite(), $setting),
                $this->userSiteSettingRepository->findByOwner($owner),
            );
        }

        $site = $this->siteRepository->findOneBySlug((string) $uriVariables['slug']);
        if (null === $site) {
            return null;
        }

        return UserSiteSettings::fromEntity($site, $this->userSiteSettingRepository->findOneBy(['owner' => $owner, 'site' => $site]));
    }
}
