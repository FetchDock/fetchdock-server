<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\DownloadJob;
use Doctrine\ORM\EntityManagerInterface;

class UserSettingsTest extends ApiTestCase
{
    use SettingsTestTrait;
    use SiteTestTrait;

    private const array EMPTY_GLOBAL = ['sendCookies' => null, 'sendUserAgent' => null, 'sendReferrer' => null, 'preferredDownloader' => null];

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testGlobalSettingsDefaultToInherit(): void
    {
        $this->assertSame(self::EMPTY_GLOBAL, $this->get('/users/settings'));
    }

    public function testPutReplacesTheGlobalLayer(): void
    {
        $data = $this->put('/users/settings', ['sendCookies' => false, 'sendReferrer' => true, 'preferredDownloader' => 'yt-dlp-cli']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['sendCookies' => false, 'sendUserAgent' => null, 'sendReferrer' => true, 'preferredDownloader' => 'yt-dlp-cli'], $data);

        // Omitted fields go back to null (inherit)
        $this->put('/users/settings', ['sendUserAgent' => false]);
        $this->assertSame(['sendCookies' => null, 'sendUserAgent' => false, 'sendReferrer' => null, 'preferredDownloader' => null], $this->get('/users/settings'));
    }

    public function testPatchMergesIntoTheGlobalLayer(): void
    {
        $this->put('/users/settings', ['sendCookies' => false]);
        $this->patch('/users/settings', ['sendReferrer' => true]);

        $this->assertSame(['sendCookies' => false, 'sendUserAgent' => null, 'sendReferrer' => true, 'preferredDownloader' => null], $this->get('/users/settings'));
    }

    public function testUnknownPreferredDownloaderIsRejected(): void
    {
        $this->put('/users/settings', ['preferredDownloader' => 'nope']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testGlobalSettingsAreIsolatedPerOwner(): void
    {
        $this->put('/users/settings', ['sendCookies' => false], 'admin');

        $this->assertSame(self::EMPTY_GLOBAL, $this->get('/users/settings'));
        $this->assertFalse($this->get('/users/settings', 'admin')['sendCookies']);
    }

    public function testDownloaderSettingsListEveryDownloader(): void
    {
        $this->put('/users/settings/downloaders/yt-dlp-cli', ['enabled' => false, 'priority' => 5, 'options' => null]);

        $members = $this->get('/users/settings/downloaders')['member'];
        $byDownloader = array_column($members, null, 'downloader');

        $this->assertArrayHasKey('gallery-dl-cli', $byDownloader);
        $this->assertSame(['downloader' => 'gallery-dl-cli', 'enabled' => null, 'priority' => null, 'options' => null], $byDownloader['gallery-dl-cli']);
        $this->assertSame(['downloader' => 'yt-dlp-cli', 'enabled' => false, 'priority' => 5, 'options' => null], $byDownloader['yt-dlp-cli']);
        $this->assertSame(['downloader' => 'yt-dlp-cli', 'enabled' => null, 'priority' => null, 'options' => null], array_column($this->get('/users/settings/downloaders', 'admin')['member'], null, 'downloader')['yt-dlp-cli']);
    }

    public function testUnknownDownloaderIsNotFound(): void
    {
        $this->client->request('GET', '/users/settings/downloaders/nope', ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(404);

        $this->put('/users/settings/downloaders/nope', ['enabled' => false]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSiteOverrides(): void
    {
        $this->assertSame([], $this->get('/users/settings/sites')['member']);
        $this->assertSame(['slug' => 'twitter', 'name' => 'Twitter / X', 'sendCookies' => null, 'sendUserAgent' => null, 'sendReferrer' => null, 'downloader' => null, 'downloaderOptions' => null], $this->get('/users/settings/sites/twitter'));

        $this->put('/users/settings/sites/twitter', ['sendCookies' => false, 'downloader' => 'yt-dlp-cli']);
        $this->assertResponseIsSuccessful();

        $members = $this->get('/users/settings/sites')['member'];
        $this->assertCount(1, $members);
        $this->assertSame(['slug' => 'twitter', 'name' => 'Twitter / X', 'sendCookies' => false, 'sendUserAgent' => null, 'sendReferrer' => null, 'downloader' => 'yt-dlp-cli', 'downloaderOptions' => null], $members[0]);
        $this->assertSame([], $this->get('/users/settings/sites', 'admin')['member']);
        $this->assertNull($this->get('/users/settings/sites/twitter', 'admin')['sendCookies']);

        $this->client->request('DELETE', '/users/settings/sites/twitter', ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([], $this->get('/users/settings/sites')['member']);
    }

    public function testUnknownSiteIsNotFound(): void
    {
        $this->put('/users/settings/sites/does-not-exist', ['sendCookies' => false]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRequiresAuthentication(): void
    {
        foreach (['/users/settings', '/users/settings/downloaders', '/users/settings/sites'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseStatusCodeSame(401);
        }
    }

    public function testResolveAppliesUserLayers(): void
    {
        $this->put('/users/settings', ['sendReferrer' => true, 'preferredDownloader' => 'yt-dlp-cli']);
        $this->put('/users/settings/sites/twitter', ['sendCookies' => false]);

        $settings = $this->resolve('https://x.com/someone/status/1')['effectiveSettings'];

        $this->assertFalse($settings['sendCookies']);
        $this->assertTrue($settings['sendReferrer']);
        $this->assertSame('yt-dlp-cli', $settings['downloader']);
        $this->assertTrue($this->resolve('https://x.com/someone/status/1', 'admin')['effectiveSettings']['sendCookies']);
    }

    public function testResolveSkipsDisabledDownloaders(): void
    {
        $this->put('/users/settings/downloaders/gallery-dl-cli', ['enabled' => false]);
        $this->assertSame('yt-dlp-cli', $this->resolve('https://x.com/someone/status/1')['effectiveSettings']['downloader']);

        $this->put('/users/settings/downloaders/yt-dlp-cli', ['enabled' => false]);
        $this->assertNull($this->resolve('https://x.com/someone/status/1')['effectiveSettings']['downloader']);
    }

    public function testResolveFollowsDownloaderPriority(): void
    {
        $this->put('/users/settings/downloaders/yt-dlp-cli', ['priority' => 1]);

        $this->assertSame('yt-dlp-cli', $this->resolve('https://x.com/someone/status/1')['effectiveSettings']['downloader']);
    }

    public function testDownloadJobHonoursRequestContext(): void
    {
        $this->put('/users/settings', ['sendCookies' => false], 'admin');

        $job = $this->createJob('https://example.com/file-'.bin2hex(random_bytes(4)).'.zip', ['downloader' => 'mock']);

        $this->assertEmpty($job->getCookies(), 'cookies are not allowed by the user settings');
        $this->assertNull($job->getReferrer(), 'referrers are not sent by default');
        $this->assertSame('TestAgent/1.0', $job->getUserAgent());
    }

    public function testDownloadJobKeepsAllowedReferrer(): void
    {
        $this->put('/users/settings', ['sendReferrer' => true], 'admin');

        $job = $this->createJob('https://example.com/file-'.bin2hex(random_bytes(4)).'.zip', ['downloader' => 'mock']);

        $this->assertSame('https://example.com/page', $job->getReferrer());
        $this->assertNotEmpty($job->getCookies());
    }

    public function testDownloadJobUsesTheEffectiveDownloader(): void
    {
        $this->put('/users/settings/sites/twitter', ['downloader' => 'yt-dlp-cli'], 'admin');

        $job = $this->createJob('https://x.com/someone/status/'.random_int(1000, PHP_INT_MAX));

        $this->assertSame('yt-dlp-cli', $job->getDownloader());
    }

    public function testDownloadJobIsRejectedWhenAllDownloadersAreDisabled(): void
    {
        $this->put('/users/settings/downloaders/gallery-dl-cli', ['enabled' => false], 'admin');
        $this->put('/users/settings/downloaders/yt-dlp-cli', ['enabled' => false], 'admin');

        $this->postJob('https://x.com/someone/status/'.random_int(1000, PHP_INT_MAX), []);

        $this->assertResponseStatusCodeSame(400);
    }

    private function createJob(string $uri, array $payload = []): DownloadJob
    {
        $uuid = $this->postJob($uri, $payload)['jobUuid'];
        $this->assertResponseStatusCodeSame(202);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $uuid]);
    }

    private function postJob(string $uri, array $payload): array
    {
        return $this->client->request('POST', '/download_jobs', [
            'json' => $payload + [
                'uri' => $uri,
                'userAgent' => 'TestAgent/1.0',
                'referrer' => 'https://example.com/page',
                'cookies' => [['domain' => '.example.com', 'name' => 'session', 'value' => 'abc', 'path' => '/', 'httpOnly' => true, 'secure' => true, 'sameSite' => 'lax', 'session' => true]],
                'force' => true,
            ],
            'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json'],
        ])->toArray(false);
    }

    private function resolve(string $url, string $user = 'user'): array
    {
        return $this->client->request('GET', '/sites/resolve', ['query' => ['url' => $url], 'headers' => self::auth($user)])->toArray();
    }

    private function get(string $path, string $user = 'user'): array
    {
        return self::withoutJsonLd($this->client->request('GET', $path, ['headers' => self::auth($user)])->toArray());
    }

    private function put(string $path, array $json, string $user = 'user'): array
    {
        return self::withoutJsonLd($this->client->request('PUT', $path, ['json' => $json, 'headers' => self::auth($user) + ['Content-Type' => 'application/ld+json']])->toArray(false));
    }

    private function patch(string $path, array $json, string $user = 'user'): array
    {
        return self::withoutJsonLd($this->client->request('PATCH', $path, ['json' => $json, 'headers' => self::auth($user) + ['Content-Type' => 'application/merge-patch+json']])->toArray(false));
    }

    /**
     * Drops all JSON-LD keywords (@id, @var, @context, ...).
     */
    private static function withoutJsonLd(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@')) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::withoutJsonLd($value);
            }
        }

        return $data;
    }
}
