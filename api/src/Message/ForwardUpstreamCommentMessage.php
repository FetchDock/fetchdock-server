<?php

namespace App\Message;

/**
 * Sends an admin's comment on a forwarded report to the collector.
 */
final readonly class ForwardUpstreamCommentMessage
{
    public function __construct(public string $commentId)
    {
    }
}
