<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Site;
use App\Model\ExtractorMatch;
use App\Repository\SiteExtractorRepository;
use App\Repository\SiteRepository;
use App\Service\Site\ExtractorMatcherInterface;
use App\Service\Site\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;

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

    public function testLogsUnsupportedUrlAndCachesExtractorMatchesWithoutLoggingTheUrl(): void
    {
        $logger = new SiteResolverTestLogger();
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->once())->method('match')->willReturn([]);
        $repository = $this->createStub(SiteRepository::class);
        $repository->method('findOneByDomain')->willReturn(null);
        $resolver = new SiteResolver(
            $matcher,
            $repository,
            $this->createStub(SiteExtractorRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new TagAwareAdapter(new ArrayAdapter()),
            $logger,
        );

        $this->assertNull($resolver->resolve('not a url'));
        $url = 'https://example.com/private-path?token=secret';
        $this->assertNull($resolver->resolve($url));
        $this->assertNull($resolver->resolve($url));

        $this->assertContains(['Cannot resolve site: URL has no host', []], $logger->debugRecords);
        $this->assertCount(2, array_filter($logger->debugRecords, static fn (array $record): bool => 'Site host cache miss' === $record[0]));
        $this->assertCount(1, array_filter($logger->debugRecords, static fn (array $record): bool => 'Extractor match cache miss' === $record[0]));
        $this->assertContains(['Extractor matches returned', ['host' => 'example.com', 'count' => 0]], $logger->debugRecords);
        $this->assertContains(['No extractors matched; site is unsupported', ['host' => 'example.com']], $logger->debugRecords);
        $this->assertStringNotContainsString('secret', json_encode($logger->debugRecords, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-path', json_encode($logger->debugRecords, JSON_THROW_ON_ERROR));
    }

    public function testLogsStaleCachedSiteAndDisabledReplacement(): void
    {
        $logger = new SiteResolverTestLogger();
        $site = new Site()->setSlug('replacement')->setEnabled(false);
        $repository = $this->createStub(SiteRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);
        $repository->method('findOneByDomain')->willReturn($site);
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $cache->get('site_resolve.example.com', static fn (): string => 'deleted');
        $resolver = new SiteResolver(
            $this->createStub(ExtractorMatcherInterface::class),
            $repository,
            $this->createStub(SiteExtractorRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $cache,
            $logger,
        );

        $this->assertNull($resolver->resolve('https://example.com/'));
        $this->assertContains(['Cached site no longer exists; resolving again', ['host' => 'example.com', 'slug' => 'deleted']], $logger->debugRecords);
        $this->assertContains(['Site found by domain', ['host' => 'example.com', 'slug' => 'replacement']], $logger->debugRecords);
        $this->assertContains(['Site resolution completed', ['host' => 'example.com', 'slug' => 'replacement', 'enabled' => false]], $logger->debugRecords);
    }

    public function testLogsExtractorMappingAndHostCacheHit(): void
    {
        $logger = new SiteResolverTestLogger();
        $site = new Site()->setSlug('family');
        $repository = $this->createStub(SiteRepository::class);
        $repository->method('findOneByDomain')->willReturn(null);
        $repository->method('findOneBySlug')->willReturn($site);
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->once())->method('match')->willReturn([new ExtractorMatch('gallery-dl-cli', 'key', 'family')]);
        $extractors = $this->createStub(SiteExtractorRepository::class);
        $extractors->method('findOneByDownloaderAndKey')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($site);
        $entityManager->expects($this->once())->method('flush');
        $resolver = new SiteResolver($matcher, $repository, $extractors, $entityManager, new TagAwareAdapter(new ArrayAdapter()), $logger);

        $this->assertSame($site, $resolver->resolve('https://example.com/first'));
        $this->assertSame($site, $resolver->resolve('https://example.com/second'));

        $this->assertContains(['Site found by extractor family', ['slug' => 'family', 'family' => 'family']], $logger->debugRecords);
        $this->assertContains(['Added extractor to site', ['slug' => 'family', 'downloader' => 'gallery-dl-cli', 'key' => 'key']], $logger->debugRecords);
        $this->assertContains(['Saved site resolution', ['host' => 'example.com', 'slug' => 'family']], $logger->debugRecords);
        $this->assertCount(1, array_filter($logger->debugRecords, static fn (array $record): bool => 'Site host cache miss' === $record[0]));
        $this->assertCount(2, array_filter($logger->debugRecords, static fn (array $record): bool => 'Site host cache returned' === $record[0]));
    }

    public function testProbesOnlyWhenOptedInAndCachesFailedProbesBriefly(): void
    {
        $matcher = $this->createMock(ExtractorMatcherInterface::class);
        $matcher->expects($this->once())->method('match')->willReturn([]);
        $matcher->expects($this->once())->method('probe')->willReturn([]);
        $repository = $this->createStub(SiteRepository::class);
        $repository->method('findOneByDomain')->willReturn(null);
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $resolver = new SiteResolver(
            $matcher,
            $repository,
            $this->createStub(SiteExtractorRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $cache,
            new SiteResolverTestLogger(),
        );
        $url = 'https://example.com/page';

        $this->assertNull($resolver->resolve($url));
        $this->assertNull($resolver->resolve($url, probe: true));
        $this->assertNull($resolver->resolve($url, probe: true));

        $this->assertEqualsWithDelta(time() + 300, $this->expiry($cache, 'site_resolve_probe.'.sha1($url)), 5);
        $this->assertEqualsWithDelta(time() + 3600, $this->expiry($cache, 'site_resolve_url.'.sha1($url)), 5);
    }

    public function testSuccessfulProbeCreatesASitePerDomain(): void
    {
        $matcher = $this->createStub(ExtractorMatcherInterface::class);
        $matcher->method('match')->willReturn([]);
        $matcher->method('probe')->willReturn([new ExtractorMatch('yt-dlp-cli', 'Generic:example.com', 'example.com')]);
        $persisted = null;
        $repository = $this->createStub(SiteRepository::class);
        $repository->method('findOneByDomain')->willReturn(null);
        $repository->method('findOneBySlug')->willReturnCallback(static function () use (&$persisted): ?Site {
            return $persisted;
        });
        $extractors = $this->createStub(SiteExtractorRepository::class);
        $extractors->method('findOneByDownloaderAndKey')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(static function (Site $site) use (&$persisted): void {
            $persisted = $site;
        });
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $resolver = new SiteResolver($matcher, $repository, $extractors, $entityManager, $cache, new SiteResolverTestLogger());
        $url = 'https://video.example.com/watch/1';

        $site = $resolver->resolve($url, probe: true);

        $this->assertSame('example-com', $site->getSlug());
        $this->assertTrue($site->hasDomain('video.example.com'));
        $this->assertSame(['Generic:example.com'], $site->getExtractors()->map(static fn ($e): string => $e->getExtractorKey())->getValues());
        $this->assertEqualsWithDelta(time() + 3600, $this->expiry($cache, 'site_resolve_probe.'.sha1($url)), 5);
    }

    private function expiry(TagAwareAdapter $cache, string $key): float
    {
        $item = $cache->getItem($key);
        $this->assertTrue($item->isHit(), $key.' is not cached');

        return $item->getMetadata()[ItemInterface::METADATA_EXPIRY];
    }
}

final class SiteResolverTestLogger extends AbstractLogger
{
    public array $debugRecords = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ('debug' === $level) {
            $this->debugRecords[] = [(string) $message, $context];
        }
    }
}
