<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\UserSettingRepository;
use App\Resource\Settings\UserSettings;
use App\Security\CurrentOwner;

/**
 * @implements ProviderInterface<UserSettings>
 */
final readonly class UserSettingsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private UserSettingRepository $userSettingRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserSettings
    {
        return UserSettings::fromEntity($this->userSettingRepository->findOneBy(['owner' => $this->currentOwner->get()]));
    }
}
