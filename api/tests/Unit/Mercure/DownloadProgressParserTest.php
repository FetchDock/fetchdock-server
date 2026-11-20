<?php

namespace App\Tests\Unit\Mercure;

use App\Mercure\DownloadProgressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DownloadProgressParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function progressLines(): iterable
    {
        yield 'in progress' => ['[download]  42.5% of    2.72MiB at    2.10MiB/s ETA 00:12', ['percent' => 42.5, 'size' => '2.72MiB', 'speed' => '2.10MiB/s', 'eta' => '00:12']];
        yield 'approximate size' => ['[download]   7.0% of ~ 150.32MiB at    5.01MiB/s ETA 00:28', ['percent' => 7.0, 'size' => '150.32MiB', 'speed' => '5.01MiB/s', 'eta' => '00:28']];
        yield 'unknown speed and ETA' => ['[download]   0.0% of    2.72MiB at  Unknown B/s ETA Unknown', ['percent' => 0.0, 'size' => '2.72MiB', 'speed' => null, 'eta' => null]];
        yield 'fragments' => ['[download]  33.3% of ~  10.00MiB at    1.00MiB/s ETA 00:07 (frag 3/9)', ['percent' => 33.3, 'size' => '10.00MiB', 'speed' => '1.00MiB/s', 'eta' => '00:07']];
        yield 'finished' => ['[download] 100% of    2.72MiB in 00:00:01 at 2.07MiB/s', ['percent' => 100.0, 'size' => '2.72MiB', 'speed' => '2.07MiB/s', 'eta' => null]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('progressLines')]
    public function testParsesYtDlpProgressLines(string $line, array $expected): void
    {
        $this->assertSame([['type' => 'progress'] + $expected], DownloadProgressParser::parse($line));
    }

    public function testParsesErrors(): void
    {
        $this->assertSame(
            [['type' => 'error', 'message' => '[youtube] abc: Requested format is not available']],
            DownloadProgressParser::parse('ERROR: [youtube] abc: Requested format is not available')
        );
    }

    public function testSkipsEverythingElse(): void
    {
        $output = implode("\n", [
            "[debug] Command-line config: ['--config', '/app/config/yt-dlp/yt-dlp.conf', '--verbose', 'https://example.com/video']",
            "[debug] Loading archive file '/downloads/download-api-yt-dlp-archive.txt'",
            '[generic] Extracting URL: https://example.com/video',
            '[download] Destination: /downloads/generic/example.com/video.mp4',
            '[download] /downloads/generic/example.com/video.mp4 has already been downloaded',
            '[download] video: video has already been recorded in the archive',
            'WARNING: [youtube] No supported JavaScript runtime could be found.',
        ]);

        $this->assertSame([], DownloadProgressParser::parse($output));
    }

    public function testParsesMultipleLinesSeparatedByCarriageReturns(): void
    {
        $events = DownloadProgressParser::parse("[download]  10.0% of 1.00MiB at 1.00MiB/s ETA 00:09\r[download]  20.0% of 1.00MiB at 1.00MiB/s ETA 00:08\r\n");

        $this->assertSame([10.0, 20.0], array_column($events, 'percent'));
    }
}
