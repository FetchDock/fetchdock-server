<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when an update occurs while handling a job.
 */
class JobUpdateEvent extends Event
{
    public function __construct(
        private DownloadJob $downloadJob,
        private string $updateMessage,
        private ?array $context = null
    ) {
    }

    public function getDownloadJob(): DownloadJob
    {
        return $this->downloadJob;
    }

    public function getUpdateMessage(): string
    {
        return $this->updateMessage;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }
}