<?php

namespace App\Tests\Unit\Service\Canonical;

use App\Service\Canonical\CanonicalProfile;
use App\Service\Canonical\UrlNormalizer;
use App\Tests\Double\CanonicalKeyGenerators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public static function defaultProfileUrls(): iterable
    {
        yield 'scheme and host lowercased' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'];
        yield 'default https port dropped' => ['https://example.com:443/a', 'https://example.com/a'];
        yield 'default http port dropped' => ['http://example.com:80/a', 'http://example.com/a'];
        yield 'other port kept' => ['https://example.com:8443/a', 'https://example.com:8443/a'];
        yield 'fragment dropped' => ['https://example.com/a#comments', 'https://example.com/a'];
        yield 'user info dropped' => ['https://user:secret@example.com/a', 'https://example.com/a'];
        yield 'empty path is /' => ['https://example.com', 'https://example.com/'];
        yield 'trailing dot of the host dropped' => ['https://example.com./a', 'https://example.com/a'];
        yield 'tracking parameters dropped' => ['https://example.com/a?utm_source=x&utm_medium=y&fbclid=1&gclid=2&si=3&igsh=4&ref_src=5&id=7', 'https://example.com/a?id=7'];
        yield 'parameter globs ignore case' => ['https://example.com/a?UTM_Source=x&id=7', 'https://example.com/a?id=7'];
        yield 'parameters sorted' => ['https://example.com/a?b=2&a=1&a=0', 'https://example.com/a?a=0&a=1&b=2'];
        yield 'empty query dropped' => ['https://example.com/a?&utm_source=x', 'https://example.com/a'];
        yield 'encoding kept' => ['https://example.com/a?q=a%20b', 'https://example.com/a?q=a%20b'];
        yield 'www kept by default' => ['https://www.example.com/a/', 'https://www.example.com/a/'];
        yield 'not a URL' => ['  not a url ', 'not a url'];
    }

    #[DataProvider('defaultProfileUrls')]
    public function testDefaultProfile(string $url, string $expected): void
    {
        $profile = CanonicalKeyGenerators::profileRegistry()->get('default');

        $this->assertSame($expected, new UrlNormalizer()->normalize($url, $profile));
    }

    public static function profileUrls(): iterable
    {
        $default = CanonicalKeyGenerators::profileRegistry()->get('default');

        yield 'stripWww' => [$default->with(['stripWww' => true], 't'), 'https://www.example.com/a', 'https://example.com/a'];
        yield 'stripTrailingSlash' => [$default->with(['stripTrailingSlash' => true], 't'), 'https://example.com/a/b//', 'https://example.com/a/b'];
        yield 'stripTrailingSlash keeps the root' => [$default->with(['stripTrailingSlash' => true], 't'), 'https://example.com/', 'https://example.com/'];
        yield 'keepParams' => [$default->with(['keepParams' => ['v', 'list*']], 't'), 'https://example.com/w?v=1&t=3&list_id=2', 'https://example.com/w?list_id=2&v=1'];
        yield 'dropParams add to the default ones' => [$default->with(['dropParams' => ['playlist_*']], 't'), 'https://example.com/v?playlist_id=1&utm_source=2&page=3', 'https://example.com/v?page=3'];
        yield 'pathRewrites' => [
            $default->with(['pathRewrites' => [['pattern' => '~^/(?:[a-z]{2}/)?video/(\d+)(?:/.*)?$~', 'replacement' => '/video/$1']]], 't', CanonicalProfile::CODE_FIELDS),
            'https://example.com/fr/video/42/some-title/',
            'https://example.com/video/42',
        ];
    }

    #[DataProvider('profileUrls')]
    public function testProfileRules(CanonicalProfile $profile, string $url, string $expected): void
    {
        $this->assertSame($expected, new UrlNormalizer()->normalize($url, $profile));
    }
}
