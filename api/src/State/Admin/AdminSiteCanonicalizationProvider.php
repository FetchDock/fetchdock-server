<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminSiteCanonicalization;
use App\Service\Canonical\CanonicalProfileRegistry;

/**
 * @implements ProviderInterface<AdminSiteCanonicalization>
 */
final readonly class AdminSiteCanonicalizationProvider implements ProviderInterface
{
    public function __construct(
        private SiteRepository $siteRepository,
        private CanonicalProfileRegistry $profileRegistry,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AdminSiteCanonicalization
    {
        $site = $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? ''));
        if (null === $site) {
            return null;
        }

        return AdminSiteCanonicalization::fromSite($site, $this->profileRegistry->forSite($site), $this->profileRegistry->names());
    }
}
