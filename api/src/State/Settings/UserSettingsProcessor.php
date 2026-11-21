<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserSetting;
use App\Mercure\SettingsChangePublisher;
use App\Repository\UserSettingRepository;
use App\Resource\Settings\UserSettings;
use App\Security\CurrentOwner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<UserSettings, UserSettings>
 */
final readonly class UserSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private UserSettingRepository $userSettingRepository,
        private EntityManagerInterface $entityManager,
        private SettingsChangePublisher $settingsChangePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserSettings
    {
        $owner = $this->currentOwner->get();
        $setting = $this->userSettingRepository->findOneBy(['owner' => $owner]) ?? new UserSetting()->setOwner($owner);

        $this->entityManager->persist($data->applyTo($setting, PatchedFields::fromContext($operation, $context)));
        $this->entityManager->flush();
        $this->settingsChangePublisher->publish($owner, SettingsChangePublisher::LAYER_USER);

        return UserSettings::fromEntity($setting);
    }
}
