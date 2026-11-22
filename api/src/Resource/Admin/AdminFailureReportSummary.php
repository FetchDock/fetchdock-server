<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\FailureReportKind;
use App\State\Admin\AdminFailureReportSummaryProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Failure report counts per host, site and kind, to prioritise site support (ADR 0012).
 */
#[ApiResource(
    shortName: 'AdminFailureReportSummary',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/failure_reports/summary',
            paginationEnabled: false,
            description: 'Report counts per host, site and kind, the most reported first.',
            parameters: [
                'since' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date-time'],
                    description: 'Only count reports created at or after this time',
                    constraints: [new Assert\DateTime(format: \DateTimeInterface::ATOM)],
                ),
                'kind' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['job_failed', 'unsupported_url']],
                    constraints: [new Assert\Choice(callback: [FailureReportKind::class, 'values'])],
                ),
            ],
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminFailureReportSummaryProvider::class,
)]
final class AdminFailureReportSummary
{
    public string $host = '';

    /** Slug of the site, null if unknown */
    public ?string $site = null;

    public FailureReportKind $kind = FailureReportKind::UNSUPPORTED_URL;

    public int $count = 0;

    public ?\DateTimeImmutable $lastReportedAt = null;
}
