<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a worker successfully completes a job.
 */
class JobCompletedEvent extends Event
{
    public function __construct(
        private DownloadJob $downloadJob,
        private ?array $metadata = null
    ) {
    }

    public function getDownloadJob(): DownloadJob
    {
        return $this->downloadJob;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
}