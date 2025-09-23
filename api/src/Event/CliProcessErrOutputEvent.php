<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Component\Process\Process;

class CliProcessErrOutputEvent extends CliProcessOutputEvent
{
    public function __construct(
        string       $output,
        ?DownloadJob $downloadJob,
        Process      $process
    ) {
        parent::__construct($output, $downloadJob, $process, true);
    }
}
