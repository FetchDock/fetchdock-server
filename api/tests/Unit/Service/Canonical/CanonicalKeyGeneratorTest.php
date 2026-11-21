<?php

namespace App\Tests\Unit\Service\Canonical;

use App\Entity\Site;
use App\Model\CanonicalExtractorMatch;
use App\Service\Canonical\CanonicalKey;
use App\Service\Site\ExtractorMatcherInterface;
use App\Tests\Double\CanonicalKeyGenerators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CanonicalKeyGeneratorTest extends TestCase
{
    public static function keys(): iterable
    {
        yield 'yt-dlp ID wins' => [
            new CanonicalExtractorMatch('Vimeo', '76979871', 'vimeo', 'video', ['76979871']),
            null, 'https://vimeo.com/76979871?share=copy', 'ytdlp:Vimeo:76979871', CanonicalKey::LAYER_YT_DLP,
        ];
        yield 'gallery-dl groups without a yt-dlp ID' => [
            new CanonicalExtractorMatch(null, null, 'imgur', 'gallery', ['abcde']),
            null, 'https://imgur.com/gallery/abcde', 'gdl:imgur:gallery:abcde', CanonicalKey::LAYER_GALLERY_DL,
        ];
        yield 'empty yt-dlp ID is ignored' => [
            new CanonicalExtractorMatch('Example', '', 'example', 'post', ['1']),
            null, 'https://example.com/p/1', 'gdl:example:post:1', CanonicalKey::LAYER_GALLERY_DL,
        ];
        yield 'idGroups select the groups (twitter profile drops the user name)' => [
            new CanonicalExtractorMatch(null, null, 'twitter', 'tweet', ['someone', '12345']),
            new Site()->setSlug('twitter'), 'https://x.com/someone/status/12345', 'gdl:twitter:tweet:12345', CanonicalKey::LAYER_GALLERY_DL,
        ];
        yield 'wildcard idGroups' => [
            new CanonicalExtractorMatch(null, null, 'example', 'post', ['user', '9']),
            new Site()->setSlug('example')->setCanonicalization(['idGroups' => ['*' => [1]]]), 'https://example.com/user/9', 'gdl:example:post:9', CanonicalKey::LAYER_GALLERY_DL,
        ];
        yield 'useExtractorId false uses the URL' => [
            new CanonicalExtractorMatch('Example', '9'),
            new Site()->setSlug('example')->setCanonicalization(['useExtractorId' => false]), 'https://Example.com/v/9?utm_source=x', 'url:https://example.com/v/9', CanonicalKey::LAYER_URL,
        ];
        yield 'unknown media uses the URL' => [
            new CanonicalExtractorMatch(),
            null, 'https://example.com/file.zip?b=2&a=1#top', 'url:https://example.com/file.zip?a=1&b=2', CanonicalKey::LAYER_URL,
        ];
        yield 'site profile normalizes the URL' => [
            new CanonicalExtractorMatch(),
            new Site()->setSlug('reddit'), 'https://www.reddit.com/r/pics/?share_id=1', 'url:https://reddit.com/r/pics', CanonicalKey::LAYER_URL,
        ];
    }

    #[DataProvider('keys')]
    public function testLayers(CanonicalExtractorMatch $match, ?Site $site, string $url, string $expectedKey, string $expectedLayer): void
    {
        $matcher = $this->createStub(ExtractorMatcherInterface::class);
        $matcher->method('canonical')->willReturn($match);

        $key = CanonicalKeyGenerators::create($matcher)->generate($url, $site);

        $this->assertSame($expectedKey, $key->key);
        $this->assertSame($expectedLayer, $key->layer);
    }

    public function testHelperFailureFallsBackToTheUrlKeyAndIsNotCached(): void
    {
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->exactly(2))->method('canonical')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('helper crashed')),
            new CanonicalExtractorMatch('Vimeo', '1'),
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $generator = CanonicalKeyGenerators::create($matcher, $logger);

        $this->assertSame('url:https://vimeo.com/1', $generator->generate('https://vimeo.com/1?utm_source=x')->key);
        $this->assertSame('ytdlp:Vimeo:1', $generator->generate('https://vimeo.com/1?utm_source=x')->key);
    }

    public function testExtractorIdentityIsCachedPerUrl(): void
    {
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->once())->method('canonical')->willReturn(new CanonicalExtractorMatch('Vimeo', '1'));
        $generator = CanonicalKeyGenerators::create($matcher);

        $generator->generate('https://vimeo.com/1');
        $this->assertSame('ytdlp:Vimeo:1', $generator->generate('https://vimeo.com/1')->key);
    }

    public function testKnownExtractorIdentitySkipsTheLookup(): void
    {
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->never())->method('canonical');

        $key = CanonicalKeyGenerators::create($matcher)->generate('https://vimeo.com/1', null, new CanonicalExtractorMatch('Vimeo', '1'));

        $this->assertSame('ytdlp:Vimeo:1', $key->key);
    }

    public function testLongKeysAreHashed(): void
    {
        $matcher = $this->createStub(ExtractorMatcherInterface::class);
        $matcher->method('canonical')->willReturn(new CanonicalExtractorMatch());
        $url = 'https://example.com/?q='.str_repeat('a', 300);

        $key = CanonicalKeyGenerators::create($matcher)->generate($url);

        $this->assertSame('url:sha256:'.hash('sha256', 'url:'.$url), $key->key);
        $this->assertLessThanOrEqual(255, strlen($key->key));
    }
}
