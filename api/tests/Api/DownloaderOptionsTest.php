<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\DownloadJob;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Downloader options (#22): options schema, user options with validation, admin policy overrides
 * and the options of download jobs. The tools' options come from tests/Double/options.
 */
class DownloaderOptionsTest extends ApiTestCase
{
    use SettingsTestTrait;
    use SiteTestTrait;

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

    public function testOptionsSchema(): void
    {
        $schema = $this->request('GET', '/downloaders/yt-dlp-cli/options-schema');
        $this->assertResponseIsSuccessful();

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('/downloaders/yt-dlp-cli/options-schema', $schema['$id']);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('2026.01.01-test', $schema['x-tool-version']);
        $this->assertSame('--limit-rate', $schema['properties']['ratelimit']['x-flag']);
        $this->assertSame('Download Options', $schema['properties']['ratelimit']['x-group']);
        $this->assertSame('boolean', $schema['properties']['writesubtitles']['type']);
        $this->assertSame('--no-write-subs', $schema['properties']['writesubtitles']['x-flag-false']);
        $this->assertSame('array', $schema['properties']['match_filter']['type']);

        foreach (['cookiefile', 'proxy', 'download_archive', 'listformats'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $schema['properties']);
        }
    }

    public function testOptionsSchemaOfDownloaderWithoutOptionsIsEmpty(): void
    {
        $schema = $this->request('GET', '/downloaders/mock/options-schema');

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $schema['properties']);
        $this->assertNull($schema['x-tool-version']);
    }

    public function testOptionsSchemaOfUnknownDownloaderIsNotFound(): void
    {
        $this->client->request('GET', '/downloaders/nope/options-schema', ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/downloaders/yt-dlp-cli/options-schema');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testDownloaderOptionsAreStoredAndReplaced(): void
    {
        $data = $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => ['ratelimit' => '2M', 'writesubtitles' => false, 'fixup' => null]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['ratelimit' => '2M', 'writesubtitles' => false], $data['options'], 'null values are dropped');

        // PATCH without options keeps them
        $this->request('PATCH', '/users/settings/downloaders/yt-dlp-cli', ['priority' => 3]);
        $this->assertSame(['ratelimit' => '2M', 'writesubtitles' => false], $this->request('GET', '/users/settings/downloaders/yt-dlp-cli')['options']);

        // PUT without options = inherit (none)
        $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['enabled' => true]);
        $this->assertNull($this->request('GET', '/users/settings/downloaders/yt-dlp-cli')['options']);
        $this->assertNull($this->request('GET', '/users/settings/downloaders/yt-dlp-cli', user: 'admin')['options']);
    }

    public function testDeniedUnknownAndInvalidOptionsAreRejected(): void
    {
        $data = $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => [
            'cookiefile' => '/etc/passwd',
            'proxy' => 'socks5://127.0.0.1',
            'nope' => true,
            'concurrent_fragment_downloads' => 'many',
            'ratelimit' => '1M',
        ]]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(
            ['options[cookiefile]', 'options[proxy]', 'options[nope]', 'options[concurrent_fragment_downloads]'],
            array_column($data['violations'], 'propertyPath'),
        );
        $this->assertNull($this->request('GET', '/users/settings/downloaders/yt-dlp-cli')['options'], 'nothing was stored');

        $this->request('PUT', '/users/settings/downloaders/mock', ['options' => ['ratelimit' => '1M']]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testSiteDownloaderOptions(): void
    {
        $data = $this->request('PUT', '/users/settings/sites/twitter', ['downloaderOptions' => ['yt-dlp-cli' => ['ratelimit' => '500K'], 'gallery-dl-cli' => ['zip' => true]]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['yt-dlp-cli' => ['ratelimit' => '500K'], 'gallery-dl-cli' => ['zip' => true]], $data['downloaderOptions']);

        $this->request('PUT', '/users/settings/sites/twitter', ['downloaderOptions' => ['yt-dlp-cli' => ['cookiefile' => '/etc/passwd']]]);
        $this->assertResponseStatusCodeSame(422);

        $data = $this->request('PUT', '/users/settings/sites/twitter', ['downloaderOptions' => ['nope' => ['ratelimit' => '1M']]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('downloaderOptions[nope]', $data['violations'][0]['propertyPath']);

        $this->assertSame(['yt-dlp-cli' => ['ratelimit' => '500K'], 'gallery-dl-cli' => ['zip' => true]], $this->request('GET', '/users/settings/sites/twitter')['downloaderOptions']);
        $this->assertNull($this->request('GET', '/users/settings/sites/twitter', user: 'admin')['downloaderOptions']);
    }

    public function testResolveReturnsTheMergedOptionsOfTheEffectiveDownloader(): void
    {
        $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => ['ratelimit' => '2M', 'writesubtitles' => true]]);
        $this->request('PUT', '/users/settings/sites/twitter', ['downloader' => 'yt-dlp-cli', 'downloaderOptions' => ['yt-dlp-cli' => ['ratelimit' => '500K']]]);

        $settings = $this->request('GET', '/sites/resolve?url='.urlencode('https://x.com/someone/status/1'))['effectiveSettings'];

        $this->assertSame('yt-dlp-cli', $settings['downloader']);
        $this->assertSame(['ratelimit' => '500K', 'writesubtitles' => true], $settings['downloaderOptions']);
    }

    public function testDownloadJobGetsTheMergedOptions(): void
    {
        $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => ['ratelimit' => '2M', 'writesubtitles' => true]], 'admin');
        $this->request('PUT', '/users/settings/sites/twitter', ['downloader' => 'yt-dlp-cli', 'downloaderOptions' => ['yt-dlp-cli' => ['ratelimit' => '500K']]], 'admin');

        $job = $this->createJob('https://x.com/someone/status/'.random_int(1000, PHP_INT_MAX));

        $this->assertSame('yt-dlp-cli', $job->getDownloader());
        $this->assertSame(['ratelimit' => '500K', 'writesubtitles' => true], $job->getDownloaderOptions());

        $explicit = $this->createJob('https://x.com/someone/status/'.random_int(1000, PHP_INT_MAX), ['downloader' => 'gallery-dl-cli']);
        $this->assertSame([], $explicit->getDownloaderOptions(), 'the options of the downloader that runs the job');
    }

    public function testPolicyOverridesRequireAdmin(): void
    {
        $this->request('GET', '/downloader_option_policies');
        $this->assertResponseStatusCodeSame(403);

        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'updatetime', 'exposed' => true]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanExposeAndHideOptions(): void
    {
        $this->assertArrayNotHasKey('updatetime', $this->request('GET', '/downloaders/yt-dlp-cli/options-schema')['properties']);

        $policy = $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'updatetime', 'exposed' => true, 'label' => 'Keep modification time'], 'admin');
        $this->assertResponseStatusCodeSame(201);
        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'ratelimit', 'exposed' => false], 'admin');
        $this->assertResponseStatusCodeSame(201);

        $properties = $this->request('GET', '/downloaders/yt-dlp-cli/options-schema')['properties'];
        $this->assertSame('Keep modification time', $properties['updatetime']['title']);
        $this->assertArrayNotHasKey('ratelimit', $properties);

        $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => ['updatetime' => false]]);
        $this->assertResponseIsSuccessful();
        $this->request('PUT', '/users/settings/downloaders/yt-dlp-cli', ['options' => ['ratelimit' => '1M']]);
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('DELETE', '/downloader_option_policies/'.$policy['id'], ['headers' => self::auth('admin')]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertArrayNotHasKey('updatetime', $this->request('GET', '/downloaders/yt-dlp-cli/options-schema')['properties']);
    }

    public function testAdminCannotExposeHardDeniedOptions(): void
    {
        foreach (['cookiefile', 'download_archive', 'proxy'] as $option) {
            $data = $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => $option, 'exposed' => true], 'admin');
            $this->assertResponseStatusCodeSame(422, $option);
            $this->assertSame('exposed', $data['violations'][0]['propertyPath']);
        }

        // Hiding is allowed
        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'cookiefile', 'exposed' => false], 'admin');
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayNotHasKey('cookiefile', $this->request('GET', '/downloaders/yt-dlp-cli/options-schema')['properties']);
    }

    public function testPolicyOverrideValidation(): void
    {
        $this->request('POST', '/downloader_option_policies', ['downloader' => 'nope', 'option' => 'x', 'exposed' => true], 'admin');
        $this->assertResponseStatusCodeSame(422);

        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'ratelimit', 'exposed' => true, 'type' => 'boolean'], 'admin');
        $this->assertResponseStatusCodeSame(422);

        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'ratelimit', 'exposed' => true], 'admin');
        $this->assertResponseStatusCodeSame(201);
        $this->request('POST', '/downloader_option_policies', ['downloader' => 'yt-dlp-cli', 'option' => 'ratelimit', 'exposed' => false], 'admin');
        $this->assertResponseStatusCodeSame(422, 'one override per option');
    }

    private function createJob(string $uri, array $payload = []): DownloadJob
    {
        $uuid = $this->request('POST', '/download_jobs', $payload + [
            'uri' => $uri,
            'force' => true,
        ], 'admin')['jobUuid'];
        $this->assertResponseStatusCodeSame(202);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $uuid]);
    }

    private function request(string $method, string $path, ?array $json = null, string $user = 'user'): array
    {
        $contentType = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        $options = ['headers' => self::auth($user) + ['Content-Type' => $contentType]];
        if (null !== $json) {
            $options['json'] = $json;
        }

        $response = $this->client->request($method, $path, $options);

        return 204 === $response->getStatusCode() ? [] : $response->toArray(false);
    }
}
