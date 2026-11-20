<?php

namespace App\Resource\Settings;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\ClientSetting;
use App\State\Settings\ClientSettingsProcessor;
use App\State\Settings\ClientSettingsProvider;
use App\Validator\SelectDownloader;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Per-client overrides, the last settings layer. Applied when the client sends its id in X-Client-Id.
 */
#[ApiResource(
    shortName: 'ClientSettings',
    operations: [
        new Get(uriTemplate: '/users/clients/{clientId}/settings'),
        new Put(
            uriTemplate: '/users/clients/{clientId}/settings',
            description: 'Replaces the client settings layer; omitted or null fields inherit.',
            extraProperties: ['standard_put' => true],
        ),
        new Patch(uriTemplate: '/users/clients/{clientId}/settings'),
        new Delete(uriTemplate: '/users/clients/{clientId}/settings', description: 'Resets the client settings layer (all fields inherit).'),
    ],
    normalizationContext: ['groups' => ['user_settings:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['user_settings:write']],
    security: "is_granted('ROLE_USER')",
    provider: ClientSettingsProvider::class,
    processor: ClientSettingsProcessor::class,
)]
final class ClientSettings
{
    #[ApiProperty(identifier: true)]
    #[Groups(['user_settings:read'])]
    public string $clientId;

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

    public static function fromEntity(string $clientId, ?ClientSetting $setting): self
    {
        $settings = new self();
        $settings->clientId = $clientId;
        $settings->sendCookies = $setting?->isSendCookies();
        $settings->sendUserAgent = $setting?->isSendUserAgent();
        $settings->sendReferrer = $setting?->isSendReferrer();
        $settings->preferredDownloader = $setting?->getPreferredDownloader();

        return $settings;
    }

    /**
     * @param list<string>|null $fields the fields to apply, null for all
     */
    public function applyTo(ClientSetting $setting, ?array $fields = null): ClientSetting
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
