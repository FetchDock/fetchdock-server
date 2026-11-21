<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserDownloaderSetting;
use App\Factory\DownloaderFactory;
use App\Mercure\SettingsChangePublisher;
use App\Repository\UserDownloaderSettingRepository;
use App\Resource\Settings\UserDownloaderSettings;
use App\Security\CurrentOwner;
use App\Service\Downloader\Options\OptionsValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<UserDownloaderSettings, UserDownloaderSettings>
 */
final readonly class UserDownloaderSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private DownloaderFactory $downloaderFactory,
        private UserDownloaderSettingRepository $userDownloaderSettingRepository,
        private EntityManagerInterface $entityManager,
        private SettingsChangePublisher $settingsChangePublisher,
        private DownloaderOptionsGuard $optionsGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserDownloaderSettings
    {
        $downloader = (string) $uriVariables['downloader'];
        if (!$this->downloaderFactory->isValidDownloader($downloader)) {
            throw new NotFoundHttpException('Downloader not found.');
        }

        $owner = $this->currentOwner->get();
        $setting = $this->userDownloaderSettingRepository->findOneBy(['owner' => $owner, 'downloader' => $downloader])
            ?? new UserDownloaderSetting()->setOwner($owner)->setDownloader($downloader);

        $fields = PatchedFields::fromContext($operation, $context);
        if (null === $fields || in_array('options', $fields, true)) {
            $this->optionsGuard->assertValid($downloader, OptionsValidator::normalize($data->options), $data, 'options');
        }

        $this->entityManager->persist($data->applyTo($setting, $fields));
        $this->entityManager->flush();
        $this->settingsChangePublisher->publish($owner, SettingsChangePublisher::LAYER_DOWNLOADER, ['downloader' => $downloader]);

        return UserDownloaderSettings::fromEntity($downloader, $setting);
    }
}
