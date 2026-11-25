<?php

namespace App\Service\FailureReport;

use App\Entity\FailureReport;

/**
 * The UpstreamFailureReport a report becomes at the collector (schemaVersion 1): the report as the user sent it, its
 * derived host and site, the admin's comment for the developers, and the consent. Never the owner, the job, the
 * internal admin note or any local id.
 */
final class UpstreamReportPayload
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function build(FailureReport $report, string $serverVersion, string $operatorPolicyVersion): array
    {
        $diagnostics = $report->getDiagnostics()?->toArray();

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'serverVersion' => mb_substr($serverVersion, 0, 64),
            'kind' => $report->getKind()?->value,
            'url' => $report->getUrl(),
            'host' => $report->getHost(),
            'site' => $report->getSiteSlug(),
            'userAgent' => $report->getUserAgent(),
            'referrer' => $report->getReferrer(),
            'diagnostics' => [] === $diagnostics ? null : $diagnostics,
            'comment' => $report->getComment(),
            'adminComment' => $report->getUpstreamComment(),
            'createdAt' => $report->getCreatedAt()->format(\DATE_ATOM),
            'consent' => [
                'policyVersion' => $report->getUpstreamPolicyVersion(),
                // The user agreed when sending the report
                'consentedAt' => $report->getCreatedAt()->format(\DATE_ATOM),
            ],
            'operatorPolicyVersion' => $operatorPolicyVersion,
        ];
    }
}
