<?php

namespace App\Event;

use App\Entity\DownloadJob;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a job fails during processing.
 */
class JobFailedEvent extends Event
{
    public function __construct(
        private DownloadJob $downloadJob,
        private \Throwable $exception,
        private ?array $context = null
    ) {
    }

    public function getDownloadJob(): DownloadJob
    {
        return $this->downloadJob;
    }

    public function getException(): \Throwable
    {
        return $this->exception;
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