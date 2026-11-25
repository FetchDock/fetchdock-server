<?php

namespace App\Enum;

/**
 * How reports the reporter agreed to share reach the collector.
 */
enum FailureReportForwardingMode: string
{
    /** An admin forwards each report, optionally with a comment for the developers */
    case REVIEW = 'review';
    /** Reports are forwarded as soon as they arrive */
    case AUTOMATIC = 'automatic';
}
