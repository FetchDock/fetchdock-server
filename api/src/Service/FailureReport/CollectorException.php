<?php

namespace App\Service\FailureReport;

/**
 * A collector call that failed. Without a status the collector couldn't be reached.
 */
final class CollectorException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** Worth trying again later: unreachable, rate limited or a server error */
    public function isRetryable(): bool
    {
        return null === $this->status || 408 === $this->status || 429 === $this->status || $this->status >= 500;
    }

    /** The collector doesn't know the instance secret (any more) */
    public function isUnauthorized(): bool
    {
        return 401 === $this->status;
    }

    public function isNotFound(): bool
    {
        return 404 === $this->status;
    }
}
