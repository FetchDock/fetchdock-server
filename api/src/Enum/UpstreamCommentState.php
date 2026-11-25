<?php

namespace App\Enum;

/**
 * Delivery state of an admin's comment on a forwarded report.
 */
enum UpstreamCommentState: string
{
    case PENDING = 'pending';
    case FORWARDED = 'forwarded';
    case FAILED = 'failed';
}
