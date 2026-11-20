<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A DownloaderOptionPolicy can't expose an option denied in code (OptionsPolicy::HARD_DENY).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ConfigurableDownloaderOption extends Constraint
{
    public string $message = 'The option "{{ option }}" of {{ downloader }} can never be exposed.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
