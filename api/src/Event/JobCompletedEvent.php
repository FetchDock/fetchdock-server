<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a worker successfully completes a job.
 */
class JobCompletedEvent extends Event
{
    public function __construct(
        private DownloadJobInterface $downloadJob,
        private ?array $metadata = null,
    ) {
    }

    public function getDownloadJob(): DownloadJobInterface
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
