<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Contracts\EventDispatcher\Event;

class ProcessStopEvent extends Event
{
    public function __construct(
        public private(set) readonly DownloadJobInterface $downloadJob,
        public private(set) readonly bool $wasSuccessful,
        public private(set) readonly ?string $errorOutput = null,
    ) {
    }
}
