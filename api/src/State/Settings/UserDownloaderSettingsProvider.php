<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Factory\DownloaderFactory;
use App\Repository\UserDownloaderSettingRepository;
use App\Resource\Settings\UserDownloaderSettings;
use App\Security\CurrentOwner;

/**
 * @implements ProviderInterface<UserDownloaderSettings>
 */
final readonly class UserDownloaderSettingsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private DownloaderFactory $downloaderFactory,
        private UserDownloaderSettingRepository $userDownloaderSettingRepository,
    ) {
    }

    /**
     * @return UserDownloaderSettings|list<UserDownloaderSettings>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserDownloaderSettings|array|null
    {
        $owner = $this->currentOwner->get();

        if ($operation instanceof CollectionOperationInterface) {
            $settings = [];
            foreach ($this->userDownloaderSettingRepository->findBy(['owner' => $owner]) as $setting) {
                $settings[$setting->getDownloader()] = $setting;
            }

            $result = [];
            foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
                $result[] = UserDownloaderSettings::fromEntity($downloader->getIdentifier(), $settings[$downloader->getIdentifier()] ?? null);
            }

            return $result;
        }

        $downloader = (string) $uriVariables['downloader'];
        if (!$this->downloaderFactory->isValidDownloader($downloader)) {
            return null;
        }

        return UserDownloaderSettings::fromEntity($downloader, $this->userDownloaderSettingRepository->findOneBy(['owner' => $owner, 'downloader' => $downloader]));
    }
}
