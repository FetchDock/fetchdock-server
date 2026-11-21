<?php

namespace App\Tests\Double;

use App\Model\CanonicalExtractorMatch;
use App\Model\ExtractorMatch;
use App\Service\Site\ExtractorMatcherInterface;

/**
 * Replaces bin/resolve_url.py in the test environment (see config/services.yaml, when@test).
 * The real helper is covered by tests/Integration/Service/ExtractorMatcherTest.
 */
final class FakeExtractorMatcher implements ExtractorMatcherInterface
{
    public static int $matchCalls = 0;
    public static int $probeCalls = 0;
    public static int $canonicalCalls = 0;

    public function match(string $url): array
    {
        ++self::$matchCalls;
        $host = parse_url($url, PHP_URL_HOST);

        return match (true) {
            in_array($host, ['x.com', 'twitter.com', 'mobile.twitter.com'], true) && str_contains($url, '/status/') => [
                new ExtractorMatch('gallery-dl-cli', 'twitter', 'twitter'),
                new ExtractorMatch('yt-dlp-cli', 'Twitter', 'twitter'),
            ],
            in_array($host, ['youtu.be', 'www.youtube.com'], true) => [new ExtractorMatch('yt-dlp-cli', 'Youtube', 'youtube')],
            is_string($host) && str_ends_with($host, 'unmapped.test') => [new ExtractorMatch('gallery-dl-cli', 'unmappedfamily', 'unmappedfamily')],
            default => [],
        };
    }

    public function probe(string $url): array
    {
        ++self::$probeCalls;
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && str_ends_with($host, 'generic.test')
            ? [new ExtractorMatch('yt-dlp-cli', 'Generic:generic.test', 'generic.test')]
            : [];
    }

    public function catalog(array $families): array
    {
        $catalog = [
            ['match' => new ExtractorMatch('gallery-dl-cli', 'twitter', 'twitter'), 'hosts' => ['x.com', 'pbs.twimg.com']],
            ['match' => new ExtractorMatch('yt-dlp-cli', 'Twitter', 'twitter'), 'hosts' => ['twitter.com', 'x.com', 'example.onion']],
            ['match' => new ExtractorMatch('yt-dlp-cli', 'TwitterSpaces', 'twitter'), 'hosts' => ['twitter.com']],
            ['match' => new ExtractorMatch('yt-dlp-cli', 'Youtube', 'youtube'), 'hosts' => ['www.youtube.com', 'music.youtube.com', 'zwearz.com']],
            ['match' => new ExtractorMatch('yt-dlp-cli', 'YoutubeTab', 'youtube'), 'hosts' => ['www.youtube.com']],
        ];

        return array_values(array_filter($catalog, static fn (array $entry): bool => in_array($entry['match']->family, $families, true)));
    }

    public function canonical(string $url): CanonicalExtractorMatch
    {
        ++self::$canonicalCalls;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            1 === preg_match('~^/([^/]+)/status/(\d+)~', $path, $m) && in_array($host, ['x.com', 'twitter.com', 'mobile.twitter.com'], true) => new CanonicalExtractorMatch('Twitter', $m[2], 'twitter', 'tweet', [$m[1], $m[2]]),
            'youtu.be' === $host && '' !== trim($path, '/') => new CanonicalExtractorMatch('Youtube', trim($path, '/')),
            'www.youtube.com' === $host && is_string($query['list'] ?? null) => new CanonicalExtractorMatch('YoutubeTab', $query['list']),
            'www.youtube.com' === $host && is_string($query['v'] ?? null) => new CanonicalExtractorMatch('Youtube', $query['v']),
            (str_ends_with($host, '.vimeo.com') || 'vimeo.com' === $host) && 1 === preg_match('~^/(\d+)~', $path, $m) => new CanonicalExtractorMatch('Vimeo', $m[1]),
            default => new CanonicalExtractorMatch(),
        };
    }

    public function canonicalBatch(array $urls): array
    {
        $result = [];
        foreach ($urls as $url) {
            $result[$url] = $this->canonical($url);
        }

        return $result;
    }
}
