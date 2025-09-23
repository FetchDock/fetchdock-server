<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Component\Process\Process;

class CliProcessStartEvent extends ProcessStartEvent
{
    public function __construct(
        private(set) string $command,
        DownloadJob $downloadJob,
        private(set) Process $process
    ) {
        parent::__construct($downloadJob);
    }
}
