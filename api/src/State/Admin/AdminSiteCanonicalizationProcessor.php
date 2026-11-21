<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\SiteCanonicalizationOverride;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminSiteCanonicalization;
use App\Service\Canonical\CanonicalProfileRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Writes or removes a site's canonicalization override, and drops the cached site profiles so new
 * submissions use it. Existing jobs keep their keys (app:jobs:canonicalize --force recomputes them).
 *
 * @implements ProcessorInterface<AdminSiteCanonicalization, AdminSiteCanonicalization|null>
 */
final readonly class AdminSiteCanonicalizationProcessor implements ProcessorInterface
{
    public function __construct(
        private SiteRepository $siteRepository,
        private CanonicalProfileRegistry $profileRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AdminSiteCanonicalization
    {
        $site = $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? ''))
            ?? throw new NotFoundHttpException('Site not found.');
        $override = $site->getCanonicalizationOverride();

        if ($operation instanceof DeleteOperationInterface) {
            if (null !== $override) {
                $site->setCanonicalizationOverride(null);
                $this->entityManager->remove($override);
                $this->entityManager->flush();
                $this->profileRegistry->invalidate();
                $this->logger->info('Removed the canonicalization override of a site', ['site' => $site->getSlug()]);
            }

            return null;
        }

        $override ??= new SiteCanonicalizationOverride();
        $site->setCanonicalizationOverride($data->applyTo($override));
        $this->entityManager->persist($override);
        $this->entityManager->flush();
        $this->profileRegistry->invalidate();
        $this->logger->info('Saved the canonicalization override of a site', ['site' => $site->getSlug(), 'override' => $override->toSettings()]);

        return AdminSiteCanonicalization::fromSite($site, $this->profileRegistry->forSite($site), $this->profileRegistry->names());
    }
}
