<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Component\Process\Process;

class CliProcessStartEvent extends ProcessStartEvent
{
    public function __construct(
        public private(set) readonly string $command,
        DownloadJobInterface $downloadJob,
        public private(set) readonly Process $process,
    ) {
        parent::__construct($downloadJob);
    }
}
