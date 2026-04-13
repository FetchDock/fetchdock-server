<?php

namespace App\Event;

use App\Model\DownloadJobInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a job fails during processing.
 */
class JobFailedEvent extends Event
{
    public function __construct(
        private readonly DownloadJobInterface $downloadJob,
        private readonly \Throwable $exception,
        private ?array $context = null,
    ) {
    }

    public function getDownloadJob(): DownloadJobInterface
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
