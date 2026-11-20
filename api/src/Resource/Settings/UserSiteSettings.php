<?php

namespace App\Resource\Settings;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\Site;
use App\Entity\UserSiteSetting;
use App\State\Settings\UserSiteSettingsProcessor;
use App\State\Settings\UserSiteSettingsProvider;
use App\Validator\SelectDownloader;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The authenticated user's overrides for one site (see /sites). Null = inherit.
 */
#[ApiResource(
    shortName: 'UserSiteSettings',
    operations: [
        new GetCollection(uriTemplate: '/users/settings/sites', description: "The user's site overrides (sites without overrides are omitted)."),
        new Get(uriTemplate: '/users/settings/sites/{slug}'),
        new Put(
            uriTemplate: '/users/settings/sites/{slug}',
            description: "Replaces the user's overrides for the site; omitted or null fields inherit.",
            extraProperties: ['standard_put' => true],
        ),
        new Patch(uriTemplate: '/users/settings/sites/{slug}'),
        new Delete(uriTemplate: '/users/settings/sites/{slug}', description: "Removes the user's overrides for the site (all fields inherit)."),
    ],
    normalizationContext: ['groups' => ['user_settings:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['user_settings:write']],
    security: "is_granted('ROLE_USER')",
    provider: UserSiteSettingsProvider::class,
    processor: UserSiteSettingsProcessor::class,
)]
final class UserSiteSettings
{
    /** The site's slug */
    #[ApiProperty(identifier: true)]
    #[Groups(['user_settings:read'])]
    public string $slug;

    /** The site's name */
    #[Groups(['user_settings:read'])]
    public string $name;

    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendCookies = null;

    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendUserAgent = null;

    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendReferrer = null;

    /** Downloader identifier (see /downloaders) */
    #[SelectDownloader]
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?string $downloader = null;

    public static function fromEntity(Site $site, ?UserSiteSetting $setting): self
    {
        $settings = new self();
        $settings->slug = (string) $site->getSlug();
        $settings->name = (string) $site->getName();
        $settings->sendCookies = $setting?->isSendCookies();
        $settings->sendUserAgent = $setting?->isSendUserAgent();
        $settings->sendReferrer = $setting?->isSendReferrer();
        $settings->downloader = $setting?->getDownloader();

        return $settings;
    }

    /**
     * @param list<string>|null $fields the fields to apply, null for all
     */
    public function applyTo(UserSiteSetting $setting, ?array $fields = null): UserSiteSetting
    {
        if (null === $fields || in_array('sendCookies', $fields, true)) {
            $setting->setSendCookies($this->sendCookies);
        }
        if (null === $fields || in_array('sendUserAgent', $fields, true)) {
            $setting->setSendUserAgent($this->sendUserAgent);
        }
        if (null === $fields || in_array('sendReferrer', $fields, true)) {
            $setting->setSendReferrer($this->sendReferrer);
        }
        if (null === $fields || in_array('downloader', $fields, true)) {
            $setting->setDownloader($this->downloader);
        }

        return $setting;
    }
}
