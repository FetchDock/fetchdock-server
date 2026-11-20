<?php

namespace App\Resource\Settings;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\UserSetting;
use App\State\Settings\UserSettingsProcessor;
use App\State\Settings\UserSettingsProvider;
use App\Validator\SelectDownloader;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The authenticated user's global settings layer. Null = inherit the server/site defaults.
 */
#[ApiResource(
    shortName: 'UserSettings',
    operations: [
        new Get(uriTemplate: '/users/settings'),
        new Put(
            uriTemplate: '/users/settings',
            description: 'Replaces the global settings layer; omitted or null fields inherit.',
            extraProperties: ['standard_put' => true],
        ),
        new Patch(uriTemplate: '/users/settings'),
    ],
    normalizationContext: ['groups' => ['user_settings:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['user_settings:write']],
    security: "is_granted('ROLE_USER')",
    provider: UserSettingsProvider::class,
    processor: UserSettingsProcessor::class,
)]
final class UserSettings
{
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendCookies = null;

    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendUserAgent = null;

    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $sendReferrer = null;

    /** Downloader identifier (see /downloaders) */
    #[SelectDownloader]
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?string $preferredDownloader = null;

    public static function fromEntity(?UserSetting $setting): self
    {
        $settings = new self();
        $settings->sendCookies = $setting?->isSendCookies();
        $settings->sendUserAgent = $setting?->isSendUserAgent();
        $settings->sendReferrer = $setting?->isSendReferrer();
        $settings->preferredDownloader = $setting?->getPreferredDownloader();

        return $settings;
    }

    /**
     * @param list<string>|null $fields the fields to apply, null for all
     */
    public function applyTo(UserSetting $setting, ?array $fields = null): UserSetting
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
        if (null === $fields || in_array('preferredDownloader', $fields, true)) {
            $setting->setPreferredDownloader($this->preferredDownloader);
        }

        return $setting;
    }
}
