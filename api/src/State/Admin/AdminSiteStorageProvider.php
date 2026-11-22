<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Site;
use App\Repository\DownloadedFileRepository;
use App\Repository\FileMoveBatchRepository;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminFileMoveBatch;
use App\Resource\Admin\AdminSiteStorage;
use App\Service\Storage\DownloadTargets;

/**
 * @implements ProviderInterface<AdminSiteStorage>
 */
final readonly class AdminSiteStorageProvider implements ProviderInterface
{
    public function __construct(
        private SiteRepository $siteRepository,
        private DownloadedFileRepository $fileRepository,
        private DownloadTargets $downloadTargets,
        private AdminDownloadDirectoryProvider $directoryProvider,
        private FileMoveBatchRepository $batchRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AdminSiteStorage
    {
        $site = $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? ''));

        return null === $site ? null : $this->toResource($site);
    }

    public function toResource(Site $site): AdminSiteStorage
    {
        $effective = $this->downloadTargets->forSite($site);
        $counts = $this->fileRepository->countForSite($site, $effective);

        $resource = new AdminSiteStorage();
        $resource->slug = (string) $site->getSlug();
        $resource->directory = null === $site->getDownloadDirectory() ? null : $this->directoryProvider->toResource($site->getDownloadDirectory());
        $resource->effectiveDirectory = $this->directoryProvider->toResource($effective);
        $resource->fileCount = $counts['total'];
        $resource->filesElsewhere = $counts['elsewhere'];
        $activeMove = $this->batchRepository->findActiveForSite($site);
        $resource->activeMove = null === $activeMove ? null : AdminFileMoveBatch::fromEntity($activeMove);

        return $resource;
    }
}
