<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\EventDispatcher\Event;

class CliProcessOutputEvent extends Event
{
    public function __construct(
        public private(set) readonly string $output,
        public private(set) readonly ?DownloadJobInterface $downloadJob,
        public private(set) readonly Process $process,
        public private(set) readonly bool $isError = false,
    ) {
    }

    public function hasDownloadJobEvent(): bool
    {
        return null !== $this->downloadJob;
    }
}
