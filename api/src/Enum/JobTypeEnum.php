<?php

namespace App\Enum;
enum JobTypeEnum
{
    case DOWNLOAD;

    public function label(): string
    {
        return match ($this) {
            self::DOWNLOAD => 'Download',
        };
    }
}
