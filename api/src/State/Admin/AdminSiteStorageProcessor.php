<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminSiteStorage;
use App\Service\FileMove\FileMovePlanner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets a site's download directory for new downloads, and with moveExisting starts moving its existing files there.
 *
 * @implements ProcessorInterface<AdminSiteStorage, AdminSiteStorage>
 */
final readonly class AdminSiteStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private SiteRepository $siteRepository,
        private AdminDownloadDirectoryProvider $directoryProvider,
        private AdminSiteStorageProvider $provider,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private FileMovePlanner $planner,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminSiteStorage
    {
        $site = $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? ''))
            ?? throw new NotFoundHttpException('Site not found.');

        if ($data->moveExisting) {
            // Before changing anything: 409 while a batch of the site is active
            $this->planner->assertNoActiveSiteBatch($site);
        }

        // The IRI was resolved to the directory's resource by API Platform (400 if it doesn't exist)
        $directory = null === $data->directory ? null : $this->directoryProvider->find($data->directory->id);
        $site->setDownloadDirectory($directory);
        $this->entityManager->flush();

        $this->logger->info('Set the download directory of a site', [
            'site' => $site->getSlug(),
            'directory' => $directory?->getId(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        if ($data->moveExisting) {
            $this->planner->planSite($site, $this->security->getUser()?->getUserIdentifier());
        }

        return $this->provider->toResource($site);
    }
}
