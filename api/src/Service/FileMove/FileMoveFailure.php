<?php

namespace App\Service\FileMove;

/**
 * A file that won't move however often it's retried (missing source, conflict, failed verification).
 */
final class FileMoveFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $skip = false)
    {
        parent::__construct($message);
    }

    /** Not an error: e.g. the file is already at the target */
    public static function skip(string $reason): self
    {
        return new self($reason, true);
    }
}
