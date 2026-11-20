<?php

namespace App\State\Settings;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserSiteSetting;
use App\Repository\SiteRepository;
use App\Repository\UserSiteSettingRepository;
use App\Resource\Settings\UserSiteSettings;
use App\Security\CurrentOwner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<UserSiteSettings, UserSiteSettings|null>
 */
final readonly class UserSiteSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentOwner $currentOwner,
        private SiteRepository $siteRepository,
        private UserSiteSettingRepository $userSiteSettingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?UserSiteSettings
    {
        $owner = $this->currentOwner->get();
        $site = $this->siteRepository->findOneBySlug((string) $uriVariables['slug'])
            ?? throw new NotFoundHttpException('Site not found.');
        $setting = $this->userSiteSettingRepository->findOneBy(['owner' => $owner, 'site' => $site]);

        if ($operation instanceof DeleteOperationInterface) {
            if (null !== $setting) {
                $owner->removeUserSiteSetting($setting);
                $this->entityManager->remove($setting);
                $this->entityManager->flush();
            }

            return null;
        }

        $setting ??= new UserSiteSetting()->setSite($site);
        $owner->addUserSiteSetting($setting);
        $this->entityManager->persist($data->applyTo($setting, PatchedFields::fromContext($operation, $context)));
        $this->entityManager->flush();

        return UserSiteSettings::fromEntity($site, $setting);
    }
}
