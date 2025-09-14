<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a worker picks up a job for processing.
 */
class JobPickedUpEvent extends Event
{
    public function __construct(
        private DownloadJob $downloadJob,
        private ?string $workerIdentifier = null
    ) {
    }

    public function getDownloadJob(): DownloadJob
    {
        return $this->downloadJob;
    }

    public function getWorkerIdentifier(): ?string
    {
        return $this->workerIdentifier;
    }

    public function setWorkerIdentifier(string $workerIdentifier): void
    {
        $this->workerIdentifier = $workerIdentifier;
    }
}