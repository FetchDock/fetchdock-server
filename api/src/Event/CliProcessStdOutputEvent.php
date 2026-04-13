<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Component\Process\Process;

class CliProcessStdOutputEvent extends CliProcessOutputEvent
{
    public function __construct(
        string $output,
        ?DownloadJobInterface $downloadJob,
        Process $process,
    ) {
        parent::__construct($output, $downloadJob, $process, false);
    }
}
