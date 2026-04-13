<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Contracts\EventDispatcher\Event;

class ProcessStartEvent extends Event
{
    public function __construct(
        public private(set) readonly DownloadJobInterface $downloadJob,
    ) {
    }
}
