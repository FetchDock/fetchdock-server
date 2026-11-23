<?php

namespace App\Exception;

/**
 * The download job was deleted (with its owner's account) while the worker ran it: the worker stops the job without
 * recording anything for it (see ProcessStoppedListener::abortWhenJobWasDeleted()).
 */
final class DownloadJobDeletedException extends \RuntimeException
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct(sprintf('Download job %d was deleted while it ran.', $jobId));
    }
}
