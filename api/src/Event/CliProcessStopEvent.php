<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Component\Process\Process;

class CliProcessStopEvent extends ProcessStopEvent
{
    public function __construct(
        DownloadJob $downloadJob,
        bool        $wasSuccessful,
        private(set) Process $process,
        private(set) readonly int  $exitCode,
        private(set) readonly string $exitCodeText,
        ?string     $errorOutput = null
    )
    {
        parent::__construct($downloadJob, $wasSuccessful, $errorOutput);
    }
}
