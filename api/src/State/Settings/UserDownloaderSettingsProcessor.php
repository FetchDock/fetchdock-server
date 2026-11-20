<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserDownloaderSetting;
use App\Factory\DownloaderFactory;
use App\Repository\UserDownloaderSettingRepository;
use App\Resource\Settings\UserDownloaderSettings;
use App\Security\CurrentOwner;
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

        $this->entityManager->persist($data->applyTo($setting, PatchedFields::fromContext($operation, $context)));
        $this->entityManager->flush();

        return UserDownloaderSettings::fromEntity($downloader, $setting);
    }
}
