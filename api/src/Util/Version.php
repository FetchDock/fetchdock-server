<?php

namespace App\Util;

class Version
{
    public static function getVersion(): string
    {
        // return the APP_VERSION environment variable
        return $_ENV['APP_VERSION'] ?? 'dev';
    }
}
