<?php

namespace App\Message;

/** Moves the file of one FileMoveItem (file-moves transport, consumed after the download queue) */
final readonly class MoveFileMessage
{
    public function __construct(public int $itemId)
    {
    }
}
