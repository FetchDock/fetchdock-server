<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Enum\FailureReportForwardingMode;
use App\State\Admin\AdminFailureReportForwardingProcessor;
use App\State\Admin\AdminFailureReportForwardingProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Forwarding of failure reports to the FetchDock collector (phase 2 of ADR 0012). Only reports whose reporter agreed
 * are forwarded, without the reporter, the job or the internal admin note.
 */
#[ApiResource(
    shortName: 'AdminFailureReportForwarding',
    operations: [
        new Get(
            uriTemplate: '/admin/failure_report_forwarding',
            description: 'Whether reports the reporters agreed to share are forwarded to the FetchDock collector, the collector\'s current privacy policy, and the instance\'s registration.',
        ),
        new Put(
            uriTemplate: '/admin/failure_report_forwarding',
            description: 'Enables or disables forwarding and sets the mode. Enabling accepts the collector privacy policy version given in acceptedPolicyVersion '
                .'(the current one, or the one accepted before while the collector still accepts it; 422 otherwise) and registers the instance with the collector on first use. '
                .'409 when forwarding is unavailable (FAILURE_REPORTS_ENABLED=0 or no FAILURE_REPORTS_COLLECTOR_URL), 503 when the collector can\'t be reached.',
            extraProperties: ['standard_put' => true],
        ),
    ],
    normalizationContext: ['groups' => ['admin_failure_report_forwarding:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['admin_failure_report_forwarding:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminFailureReportForwardingProvider::class,
    processor: AdminFailureReportForwardingProcessor::class,
)]
final class AdminFailureReportForwarding
{
    #[Groups(['admin_failure_report_forwarding:read', 'admin_failure_report_forwarding:write'])]
    public bool $enabled = false;

    /** review: an admin forwards each report, optionally with a comment; automatic: reports are forwarded right away */
    #[Groups(['admin_failure_report_forwarding:read', 'admin_failure_report_forwarding:write'])]
    public FailureReportForwardingMode $mode = FailureReportForwardingMode::REVIEW;

    /** The collector policy version the admin accepted. Required to enable forwarding. */
    #[Assert\Length(max: 64)]
    #[Groups(['admin_failure_report_forwarding:read', 'admin_failure_report_forwarding:write'])]
    public ?string $acceptedPolicyVersion = null;

    #[Groups(['admin_failure_report_forwarding:read'])]
    public ?\DateTimeImmutable $acceptedAt = null;

    /** False when the server disabled failure reports or configured no collector */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public bool $available = false;

    /** FAILURE_REPORTS_COLLECTOR_URL */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public ?string $collectorUrl = null;

    /** The collector's current policy, null while it can't be reached */
    #[ApiProperty(schema: [
        'type' => ['object', 'null'],
        'properties' => [
            'version' => ['type' => 'string'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'acceptedVersions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ])]
    #[Groups(['admin_failure_report_forwarding:read'])]
    public ?array $policy = null;

    /** Whether the collector still accepts the version the admin accepted; reports are held otherwise */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public bool $acceptedPolicyCurrent = false;

    /** The id the collector gave this instance, to refer to it when contacting the FetchDock project */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public ?string $instanceId = null;

    #[Groups(['admin_failure_report_forwarding:read'])]
    public ?\DateTimeImmutable $registeredAt = null;

    /** Reports the reporters agreed to share that wait for an admin (review mode, or held) */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public int $awaitingReview = 0;

    /** Reports the collector refused or that couldn't be sent */
    #[Groups(['admin_failure_report_forwarding:read'])]
    public int $failed = 0;
}
