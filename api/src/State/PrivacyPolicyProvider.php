<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Resource\PrivacyPolicy;
use App\Service\Integrity\IntegrityReport;
use App\Service\Privacy\PrivacyPolicyPublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GET /privacy_policy: the published policy and the integrity status (ADR 0013).
 *
 * @implements ProviderInterface<PrivacyPolicy>
 */
final readonly class PrivacyPolicyProvider implements ProviderInterface
{
    public function __construct(
        private PrivacyPolicyPublisher $publisher,
        private IntegrityReport $integrityReport,
        #[Autowire('%app.version%')]
        private string $appVersion,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PrivacyPolicy
    {
        [$status, $reason] = $this->publisher->resolve();
        $revision = $this->publisher->currentRevision($status);

        $policy = new PrivacyPolicy();
        $policy->status = $status;
        $policy->reason = $reason;
        $policy->url = $this->publisher->url($status);
        $policy->revision = $revision?->getNumber();
        $policy->textVersion = $revision?->getTextVersion();
        $policy->appVersion = $this->appVersion;
        $policy->updatedAt = $revision?->getCreatedAt();
        $policy->outdated = $this->publisher->isOutdated($status);
        $policy->integrity = $this->integrityReport->summary();

        return $policy;
    }
}
