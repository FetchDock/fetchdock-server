<?php

namespace App\Enum;

enum DownloadStateEnum: int
{
    case PENDING = 0;
    case IN_PROGRESS = 1;
    case COMPLETED = 2;
    case FAILED = 3;
    case CANCELED = 4;
}
