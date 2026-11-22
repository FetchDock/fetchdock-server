<?php

namespace App\Enum;

/**
 * What a failure report is about (ADR 0012).
 */
enum FailureReportKind: string
{
    /** One of the reporter's download jobs failed; the report links the job */
    case JOB_FAILED = 'job_failed';
    /** A page FetchDock can't handle, submitted or not */
    case UNSUPPORTED_URL = 'unsupported_url';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
