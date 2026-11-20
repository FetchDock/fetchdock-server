<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Site;
use App\Entity\SiteSetting;
use App\Repository\SiteRepository;
use App\Tests\Double\FakeExtractorMatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class SiteResolveTest extends ApiTestCase
{
    use SiteTestTrait;

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
    }

    public static function twitterUrls(): iterable
    {
        yield 'x.com' => ['https://x.com/someone/status/1'];
        yield 'twitter.com' => ['https://twitter.com/someone/status/1'];
    }

    #[DataProvider('twitterUrls')]
    public function testResolvesTwitterUrls(string $url): void
    {
        $data = $this->resolve($url);

        $this->assertResponseIsSuccessful();
        $this->assertSame($url, $data['url']);
        $this->assertSame(parse_url($url, PHP_URL_HOST), $data['host']);
        $this->assertSame(['slug' => 'twitter', 'name' => 'Twitter / X'], $data['site']);
        $this->assertContains(['downloader' => 'gallery-dl-cli', 'key' => 'twitter'], $data['extractors']);
        $this->assertContains(['downloader' => 'yt-dlp-cli', 'key' => 'Twitter'], $data['extractors']);
        $this->assertSame([
            'sendCookies' => true,
            'sendUserAgent' => true,
            'sendReferrer' => false,
            'downloader' => 'gallery-dl-cli',
            'downloaderOptions' => [],
        ], $data['effectiveSettings']);
        $this->assertSame(['cookies' => true, 'userAgent' => true, 'referrer' => false], $data['requestContext']);
    }

    public function testResolvesYoutuBeToYoutube(): void
    {
        $data = $this->resolve('https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame('youtube', $data['site']['slug']);
        $this->assertSame('yt-dlp-cli', $data['effectiveSettings']['downloader']);
    }

    public function testEmptyDownloaderOptionsAreAJsonObject(): void
    {
        $this->client->request('GET', '/sites/resolve', ['query' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'], 'headers' => self::auth() + ['Accept' => 'application/json']]);

        $this->assertStringContainsString('"downloaderOptions":{}', $this->client->getResponse()->getContent());
    }

    public function testUnsupportedUrlIsNotAnError(): void
    {
        $data = $this->resolve('https://example.com/some/page');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('example.com', $data['host']);
        $this->assertNull($data['site']);
        $this->assertSame([], $data['extractors']);
        $this->assertNull($data['effectiveSettings']['downloader']);
    }

    public function testKnownDomainResolvesWithoutRunningTheMatcher(): void
    {
        FakeExtractorMatcher::$matchCalls = 0;

        $data = $this->resolve('https://music.youtube.com/watch?v=abc');

        $this->assertSame('youtube', $data['site']['slug']);
        $this->assertSame(0, FakeExtractorMatcher::$matchCalls);
    }

    public function testHostResolutionIsCached(): void
    {
        $this->resolve('https://x.com/someone/status/1');
        FakeExtractorMatcher::$matchCalls = 0;

        $data = $this->resolve('https://x.com/someone-else/status/2');

        $this->assertSame('twitter', $data['site']['slug']);
        $this->assertSame(0, FakeExtractorMatcher::$matchCalls);
    }

    public function testUnmappedExtractorCreatesAnAutomaticSiteAndLearnsTheHost(): void
    {
        $host = 'a'.bin2hex(random_bytes(4)).'.unmapped.test';

        $data = $this->resolve('https://'.$host.'/gallery/1');

        $this->assertSame('unmappedfamily', $data['site']['slug']);
        $this->assertSame([['downloader' => 'gallery-dl-cli', 'key' => 'unmappedfamily']], $data['extractors']);

        $site = static::getContainer()->get(SiteRepository::class)->findOneBySlug('unmappedfamily');
        static::getContainer()->get(EntityManagerInterface::class)->refresh($site);
        $this->assertFalse($site->isCurated());
        $this->assertTrue($site->hasDomain($host));
    }

    public function testSiteDefaultsApply(): void
    {
        $this->setTwitterDefaults(new SiteSetting()->setSendCookies(false)->setSendReferrer(true)->setDownloader('yt-dlp-cli'));

        try {
            $data = $this->resolve('https://x.com/someone/status/1');

            $this->assertFalse($data['effectiveSettings']['sendCookies']);
            $this->assertTrue($data['effectiveSettings']['sendUserAgent']);
            $this->assertTrue($data['effectiveSettings']['sendReferrer']);
            $this->assertSame('yt-dlp-cli', $data['effectiveSettings']['downloader']);
            $this->assertSame(['cookies' => false, 'userAgent' => true, 'referrer' => true], $data['requestContext']);
        } finally {
            $this->setTwitterDefaults(null);
        }
    }

    public function testDisabledSiteResolvesAsUnsupported(): void
    {
        $this->setTwitterEnabled(false);

        try {
            $data = $this->resolve('https://x.com/someone/status/1');

            $this->assertNull($data['site']);
            $this->assertSame([], $data['extractors']);
        } finally {
            $this->setTwitterEnabled(true);
        }
    }

    public static function invalidUrls(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not a url' => ['not a url'];
        yield 'ftp' => ['ftp://example.com/file'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'too long' => ['https://example.com/'.str_repeat('a', 2048)];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(?string $url): void
    {
        $this->client->request('GET', '/sites/resolve', ['query' => null === $url ? [] : ['url' => $url], 'headers' => self::auth()]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/sites/resolve', ['query' => ['url' => 'https://x.com/someone/status/1']]);

        $this->assertResponseStatusCodeSame(401);
    }

    private function resolve(string $url): array
    {
        return self::withoutJsonLdKeywords($this->client->request('GET', '/sites/resolve', ['query' => ['url' => $url], 'headers' => self::auth()])->toArray());
    }

    private function setTwitterDefaults(?SiteSetting $setting): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $site = $entityManager->getRepository(Site::class)->findOneBy(['slug' => 'twitter']);
        if (null !== $site->getDefaultSetting()) {
            $entityManager->remove($site->getDefaultSetting());
            $entityManager->flush();
            $entityManager->refresh($site);
        }
        if (null !== $setting) {
            $site->setDefaultSetting($setting);
            $entityManager->persist($setting);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function setTwitterEnabled(bool $enabled): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getRepository(Site::class)->findOneBy(['slug' => 'twitter'])->setEnabled($enabled);
        $entityManager->flush();
        $entityManager->clear();
    }
}
