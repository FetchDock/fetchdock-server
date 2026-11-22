<?php

namespace App\Enum;

/**
 * The kind of container that ran an integrity check. Both run the api image.
 */
enum IntegrityRole: string
{
    case API = 'api';
    case WORKER = 'worker';

    /**
     * The role of the current container from NODE_MODE (php or worker, see docker-entrypoint.sh).
     */
    public static function fromNodeMode(?string $nodeMode): self
    {
        return 'worker' === $nodeMode ? self::WORKER : self::API;
    }
}
