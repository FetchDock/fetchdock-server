<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminSiteStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets a site's download directory for new downloads. Existing files stay where they are (moves come with #109 PR 3).
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
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminSiteStorage
    {
        $site = $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? ''))
            ?? throw new NotFoundHttpException('Site not found.');

        // The IRI was resolved to the directory's resource by API Platform (400 if it doesn't exist)
        $directory = null === $data->directory ? null : $this->directoryProvider->find($data->directory->id);
        $site->setDownloadDirectory($directory);
        $this->entityManager->flush();

        $this->logger->info('Set the download directory of a site', [
            'site' => $site->getSlug(),
            'directory' => $directory?->getId(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        return $this->provider->toResource($site);
    }
}
