<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

class ProcessStartEvent extends Event
{
    public function __construct(
        private(set) DownloadJob $downloadJob,
    )
    {
    }
}
