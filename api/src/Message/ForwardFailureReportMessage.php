<?php

namespace App\Message;

/**
 * Sends a report the reporter agreed to share to the collector (phase 2 of ADR 0012). Retried on the
 * "failure-reports" transport while the collector can't be reached.
 */
final readonly class ForwardFailureReportMessage
{
    public function __construct(public string $reportId)
    {
    }
}
