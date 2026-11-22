<?php

namespace App\Message;

/** Measures a planned batch's files and dispatches one MoveFileMessage per file (file-moves transport) */
final readonly class StartFileMoveBatchMessage
{
    public function __construct(public int $batchId)
    {
    }
}
