<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\FailureReport;
use App\Enum\FailureReportForwarding;
use App\Repository\FailureReportForwardingSettingsRepository;
use App\Resource\Admin\AdminFailureReportForwarding;
use App\Service\FailureReport\CollectorClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GET /admin/failure_report_forwarding.
 *
 * @implements ProviderInterface<AdminFailureReportForwarding>
 */
final readonly class AdminFailureReportForwardingProvider implements ProviderInterface
{
    public function __construct(
        private FailureReportForwardingSettingsRepository $settingsRepository,
        private CollectorClient $client,
        private EntityManagerInterface $entityManager,
        #[Autowire('%app.failure_reports.enabled%')]
        private bool $reportsEnabled,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminFailureReportForwarding
    {
        return $this->current();
    }

    public function current(): AdminFailureReportForwarding
    {
        $settings = $this->settingsRepository->get();
        $resource = new AdminFailureReportForwarding();
        $resource->enabled = $settings->isEnabled();
        $resource->mode = $settings->getMode();
        $resource->acceptedPolicyVersion = $settings->getAcceptedPolicyVersion();
        $resource->acceptedAt = $settings->getAcceptedAt();
        $resource->available = $this->reportsEnabled && $this->client->isConfigured();
        $resource->collectorUrl = $this->client->isConfigured() ? $this->client->getCollectorUrl() : null;

        $policy = $resource->available ? $this->client->policy() : null;
        $resource->policy = $policy?->toArray();
        $resource->acceptedPolicyCurrent = null !== $policy && $policy->accepts($settings->getAcceptedPolicyVersion());

        if ($settings->isRegisteredWith($this->client->getCollectorUrl())) {
            $resource->instanceId = $settings->getInstanceId()?->toRfc4122();
            $resource->registeredAt = $settings->getRegisteredAt();
        }

        /** @var array<string, int> $counts */
        $counts = $this->entityManager->createQuery(\sprintf('SELECT r.forwarding AS forwarding, COUNT(r.id) AS n FROM %s r WHERE r.forwarding IN (:states) GROUP BY r.forwarding', FailureReport::class))
            ->setParameter('states', [FailureReportForwarding::AWAITING_REVIEW, FailureReportForwarding::FAILED])
            ->getResult();
        foreach ($counts as $row) {
            match ($row['forwarding']) {
                FailureReportForwarding::AWAITING_REVIEW => $resource->awaitingReview = (int) $row['n'],
                FailureReportForwarding::FAILED => $resource->failed = (int) $row['n'],
                default => null,
            };
        }

        return $resource;
    }
}
