<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

class ProcessStopEvent extends Event
{
    public function __construct(
        private(set) DownloadJob $downloadJob,
        private(set) readonly bool $wasSuccessful,
        private(set) readonly ?string $errorOutput = null
    )
    {

    }
}
