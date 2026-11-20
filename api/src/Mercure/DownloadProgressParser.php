<?php

namespace App\Mercure;

/**
 * Turns downloader output into structured progress events for clients.
 *
 * Only lines with a meaning for clients are turned into events: download progress and errors. Everything else, such as
 * debug output, command lines and server paths, is skipped. The full output stays available in the job's events.
 */
final class DownloadProgressParser
{
    /**
     * yt-dlp progress line, e.g.
     * "[download]  42.5% of    2.72MiB at    2.10MiB/s ETA 00:12"
     * "[download]  42.5% of ~  2.72MiB at  Unknown B/s ETA Unknown (frag 3/10)"
     * "[download] 100% of    2.72MiB in 00:00:01 at 2.07MiB/s".
     */
    private const string PROGRESS_PATTERN = '/^\[download\]\s+(?<percent>\d+(?:\.\d+)?)%(?:\s+of\s+~?\s*(?<size>[\d.]+\s*[KMGT]?i?B))?(?:\s+in\s+\S+)?(?:\s+at\s+(?<speed>[\d.]+\s*[KMGT]?i?B\/s))?(?:.*?\s+ETA\s+(?<eta>\d[\d:]*))?/';

    /**
     * @return list<array{type: 'progress', percent: float, size: ?string, speed: ?string, eta: ?string}|array{type: 'error', message: string}>
     */
    public static function parse(string $output): array
    {
        $events = [];
        foreach (preg_split('/[\r\n]+/', $output) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            if (1 === preg_match(self::PROGRESS_PATTERN, $line, $matches)) {
                $events[] = [
                    'type' => 'progress',
                    'percent' => min(100.0, (float) $matches['percent']),
                    'size' => self::valueOrNull($matches, 'size'),
                    'speed' => self::valueOrNull($matches, 'speed'),
                    'eta' => self::valueOrNull($matches, 'eta'),
                ];
            } elseif (str_starts_with($line, 'ERROR:')) {
                $events[] = ['type' => 'error', 'message' => trim(substr($line, strlen('ERROR:')))];
            }
        }

        return $events;
    }

    /**
     * @param array<string, string> $matches
     */
    private static function valueOrNull(array $matches, string $name): ?string
    {
        $value = $matches[$name] ?? '';

        return '' === $value ? null : preg_replace('/\s+/', '', $value);
    }
}
