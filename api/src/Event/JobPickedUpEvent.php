<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a worker picks up a job for processing.
 */
class JobPickedUpEvent extends Event
{
    public function __construct(
        private readonly DownloadJobInterface $downloadJob,
        private ?string $workerIdentifier = null,
    ) {
    }

    public function getDownloadJob(): DownloadJobInterface
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
