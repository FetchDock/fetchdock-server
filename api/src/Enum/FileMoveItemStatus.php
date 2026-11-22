<?php

namespace App\Enum;

enum FileMoveItemStatus: string
{
    case PENDING = 'pending';
    /** Being copied by a worker; a file can be in only one pending or moving item (partial unique index) */
    case MOVING = 'moving';
    case MOVED = 'moved';
    /** Not moved on purpose: shared with another site, job in progress, in another batch, cancelled, ... */
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
}
