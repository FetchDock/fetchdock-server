<?php

namespace App\Resource\Settings;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\UserDownloaderSetting;
use App\Service\Downloader\Options\OptionsValidator;
use App\State\Settings\UserDownloaderSettingsProcessor;
use App\State\Settings\UserDownloaderSettingsProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The authenticated user's settings for one downloader (see /downloaders). Null = inherit.
 */
#[ApiResource(
    shortName: 'UserDownloaderSettings',
    operations: [
        new GetCollection(uriTemplate: '/users/settings/downloaders', description: 'Settings for every downloader, including downloaders without user settings (all fields null).'),
        new Get(uriTemplate: '/users/settings/downloaders/{downloader}'),
        new Put(
            uriTemplate: '/users/settings/downloaders/{downloader}',
            description: 'Replaces the user settings of the downloader; omitted or null fields inherit.',
            extraProperties: ['standard_put' => true],
        ),
        new Patch(uriTemplate: '/users/settings/downloaders/{downloader}'),
    ],
    normalizationContext: ['groups' => ['user_settings:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['user_settings:write']],
    security: "is_granted('ROLE_USER')",
    provider: UserDownloaderSettingsProvider::class,
    processor: UserDownloaderSettingsProcessor::class,
)]
final class UserDownloaderSettings
{
    /** Downloader identifier (see /downloaders) */
    #[ApiProperty(identifier: true)]
    #[Groups(['user_settings:read'])]
    public string $downloader;

    /** False never uses this downloader. Null = enabled */
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?bool $enabled = null;

    /** Lower values are preferred when no layer picks a downloader. Null = server order, after prioritised downloaders */
    #[Assert\Range(min: -1000, max: 1000)]
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?int $priority = null;

    /**
     * Command line options, validated against GET /downloaders/{id}/options-schema. Null values are dropped, null = none.
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(openapiContext: ['type' => ['object', 'null'], 'additionalProperties' => true])]
    #[Groups(['user_settings:read', 'user_settings:write'])]
    public ?array $options = null;

    public static function fromEntity(string $downloader, ?UserDownloaderSetting $setting): self
    {
        $settings = new self();
        $settings->downloader = $downloader;
        $settings->enabled = $setting?->isEnabled();
        $settings->priority = $setting?->getPriority();
        $settings->options = $setting?->getOptions();

        return $settings;
    }

    /**
     * @param list<string>|null $fields the fields to apply, null for all
     */
    public function applyTo(UserDownloaderSetting $setting, ?array $fields = null): UserDownloaderSetting
    {
        if (null === $fields || in_array('enabled', $fields, true)) {
            $setting->setEnabled($this->enabled);
        }
        if (null === $fields || in_array('priority', $fields, true)) {
            $setting->setPriority($this->priority);
        }
        if (null === $fields || in_array('options', $fields, true)) {
            $setting->setOptions(OptionsValidator::normalize($this->options));
        }

        return $setting;
    }
}
