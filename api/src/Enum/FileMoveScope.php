<?php

namespace App\Enum;

/** What a file move batch selects (ADR 0011) */
enum FileMoveScope: string
{
    /** The files of a site's jobs that aren't in its effective directory */
    case SITE = 'site';
    /** Every file of a source directory, whatever its site */
    case DIRECTORY = 'directory';
}
