<?php

namespace App\Enum;

/**
 * Triage state of a failure report, set by admins.
 */
enum FailureReportStatus: string
{
    case NEW = 'new';
    case ACKNOWLEDGED = 'acknowledged';
    case RESOLVED = 'resolved';
    case WONT_FIX = 'wont_fix';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
