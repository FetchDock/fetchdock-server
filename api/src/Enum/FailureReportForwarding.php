<?php

namespace App\Enum;

/**
 * Where a report stands on its way to the FetchDock collector (phase 2 of ADR 0012).
 */
enum FailureReportForwarding: string
{
    /** The reporter didn't agree to share it */
    case NOT_REQUESTED = 'not_requested';
    /** Waits for an admin to forward it (review mode), or was held because forwarding can't go ahead */
    case AWAITING_REVIEW = 'awaiting_review';
    /** Queued for the collector */
    case PENDING = 'pending';
    case FORWARDED = 'forwarded';
    /** The collector refused it or kept failing; an admin can try again */
    case FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
