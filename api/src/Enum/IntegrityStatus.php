<?php

namespace App\Enum;

/**
 * Result of an instance integrity check (ADR 0013). A signal that catches honest drift, not proof.
 */
enum IntegrityStatus: string
{
    /** The files match the image's manifest and the manifest is the one CI published for this version. */
    case VERIFIED = 'verified';
    /** A file differs from the manifest, or the manifest isn't the one CI published for this version. */
    case MODIFIED = 'modified';
    /** No manifest (dev), offline, the check is disabled, or CI published no digest for this version (forks). */
    case UNKNOWN = 'unknown';

    /**
     * Higher is worse: the effective status of several checks is the one with the highest severity.
     */
    public function severity(): int
    {
        return match ($this) {
            self::VERIFIED => 0,
            self::UNKNOWN => 1,
            self::MODIFIED => 2,
        };
    }

    public static function worst(self ...$statuses): self
    {
        $worst = null;
        foreach ($statuses as $status) {
            if (null === $worst || $status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst ?? self::UNKNOWN;
    }
}
