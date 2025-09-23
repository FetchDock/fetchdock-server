<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Component\Process\Process;
use Symfony\Contracts\EventDispatcher\Event;

class CliProcessOutputEvent extends Event
{
    public function __construct(
        private(set) string         $output,
        private(set) ?DownloadJob   $downloadJob,
        private(set) Process        $process,
        private(set) bool           $isError = false
    ) {
    }

    public function hasDownloadJobEvent(): bool
    {
        return null !== $this->downloadJob;
    }
}
