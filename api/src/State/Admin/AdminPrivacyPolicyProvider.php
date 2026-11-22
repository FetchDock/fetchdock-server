<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Enum\PrivacyPolicyStatus;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Resource\Admin\AdminPrivacyPolicy;
use App\Service\Integrity\IntegrityReport;
use App\Service\Privacy\PrivacyPolicyPublisher;

/**
 * GET /admin/privacy_policy: the operator's answers, publication status and pending review state (ADR 0013).
 *
 * @implements ProviderInterface<AdminPrivacyPolicy>
 */
final readonly class AdminPrivacyPolicyProvider implements ProviderInterface
{
    public function __construct(
        private PrivacyPolicySettingsRepository $settingsRepository,
        private PrivacyPolicyPublisher $publisher,
        private IntegrityReport $integrityReport,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminPrivacyPolicy
    {
        return $this->current();
    }

    public function current(): AdminPrivacyPolicy
    {
        $resource = AdminPrivacyPolicy::fromAnswers($this->settingsRepository->get()->getAnswers());
        [$resource->status, $resource->reason] = $this->publisher->resolve();
        $current = $this->publisher->currentRevision($resource->status);
        $resource->revision = $current?->getNumber();
        $resource->integrity = $this->integrityReport->summary();

        $pending = $this->publisher->pendingUpdate();
        $resource->publishedTextVersion = $pending->publishedTextVersion;
        $resource->currentTextVersion = $pending->currentTextVersion;
        if (PrivacyPolicyStatus::CUSTOM !== $resource->status) {
            $resource->outdated = PrivacyPolicyStatus::GENERATED === $resource->status && $pending->isOutdated();
            $resource->pendingPublish = $pending->hasChanges();
            $resource->pendingReasons = $pending->reasons();
            $resource->pendingTextChanges = $pending->textChanges;
        }

        return $resource;
    }
}
