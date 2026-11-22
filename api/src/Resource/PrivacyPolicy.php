<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Model\IntegritySummary;
use App\State\PrivacyPolicyProvider;

/**
 * The instance's privacy policy, for clients (ADR 0013). Public.
 */
#[ApiResource(
    shortName: 'PrivacyPolicy',
    operations: [
        new Get(
            uriTemplate: '/privacy_policy',
            description: "Which privacy policy the instance publishes and the integrity status of its code. Public. The generated policy is at /privacy (HTML, ?revision=N for earlier revisions); it is only published while the integrity check reads verified and the operator's answers are complete.",
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    provider: PrivacyPolicyProvider::class,
)]
final class PrivacyPolicy
{
    public PrivacyPolicyStatus $status = PrivacyPolicyStatus::UNAVAILABLE;

    /** Why no policy is published, null otherwise */
    public ?PrivacyPolicyUnavailableReason $reason = null;

    /** /privacy (absolute) for the generated policy, the operator's URL for a custom one, null when unavailable */
    public ?string $url = null;

    /** The generated policy's current revision */
    public ?int $revision = null;

    /** The generated policy's text version (privacy/policy-version.yaml) */
    public ?string $textVersion = null;

    /** The FetchDock version the instance runs */
    public string $appVersion = '';

    /** When the current revision was created */
    public ?\DateTimeImmutable $updatedAt = null;

    /** True when the generated policy is published, but the running software or configuration changed since the operator last approved it */
    public bool $outdated = false;

    public IntegritySummary $integrity;

    public function __construct()
    {
        $this->integrity = new IntegritySummary();
    }
}
