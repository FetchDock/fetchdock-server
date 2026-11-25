<?php

namespace App\Message;

/**
 * Deletes a forwarded report at the collector after its reporter withdrew it or deleted their account. The local
 * report is gone by then, so the message carries the collector's id. Retried for about two days, then kept in the
 * "failed" transport (messenger:failed:retry).
 */
final readonly class DeleteUpstreamReportMessage
{
    public function __construct(
        public string $upstreamId,
        /** The collector the report went to; a report on another collector can't be deleted from here */
        public string $collectorUrl,
    ) {
    }
}
