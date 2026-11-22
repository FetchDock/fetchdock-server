<?php

namespace App\Enum;

enum FileMoveBatchStatus: string
{
    /** Planned, waiting for the worker to measure and dispatch its files */
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    /** Done, but some files failed */
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    /** Stopped by an admin; files already moved stay moved */
    case CANCELLED = 'cancelled';
    /** The batch itself couldn't run, e.g. its target directory isn't mounted in the worker */
    case FAILED = 'failed';

    public function isActive(): bool
    {
        return self::QUEUED === $this || self::RUNNING === $this;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::QUEUED->value, self::RUNNING->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
