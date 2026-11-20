<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\SiteResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteResolverTest extends TestCase
{
    public static function hosts(): iterable
    {
        yield ['https://X.com/a/status/1', 'x.com'];
        yield ['https://www.youtube.com./watch?v=1', 'www.youtube.com'];
        yield ['https://user:pass@example.com:8443/path', 'example.com'];
        yield ['not a url', null];
    }

    #[DataProvider('hosts')]
    public function testGetHost(string $url, ?string $expected): void
    {
        $this->assertSame($expected, SiteResolver::getHost($url));
    }

    public static function slugs(): iterable
    {
        yield ['twitter', 'twitter'];
        yield ['Twitter / X', 'twitter-x'];
        yield ['yt_dlp.Some__Family', 'yt-dlp-some-family'];
        yield [str_repeat('a', 80), str_repeat('a', 64)];
    }

    #[DataProvider('slugs')]
    public function testSlugify(string $value, string $expected): void
    {
        $this->assertSame($expected, SiteResolver::slugify($value));
    }

    public function testSlugifyNeverReturnsAnEmptySlug(): void
    {
        $this->assertMatchesRegularExpression('/^site-[0-9a-f]{8}$/', SiteResolver::slugify('日本'));
    }
}
