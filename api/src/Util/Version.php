<?php

namespace App\Util;

class Version
{
    public static function getVersion(): string
    {
        // Look for the VERSION file which is generated during RELEASE
        // If the file does not exist, we're probably running from source
        return file_exists(__DIR__.'/../../VERSION')
            ? file_get_contents(__DIR__.'/../../VERSION')
            : 'dev';
    }
}
