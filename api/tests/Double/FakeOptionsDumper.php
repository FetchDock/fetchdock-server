<?php

namespace App\Tests\Double;

use App\Service\Downloader\Options\OptionsDumperInterface;

/**
 * Replaces bin/dump_options.py in the test environment (see config/services.yaml, when@test) with
 * excerpts of real dumps (tests/Double/options/*.json). The real helper is covered by
 * tests/Integration/Service/OptionsDumperTest.
 */
final class FakeOptionsDumper implements OptionsDumperInterface
{
    public static int $dumpCalls = 0;

    public function dump(string $tool): array
    {
        ++self::$dumpCalls;
        $path = __DIR__.'/options/'.basename($tool).'.json';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Unable to list the options of %s.', $tool));
        }

        return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }
}
