<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\OidcSubjectIdentifier;
use App\Mercure\UserTopics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\Debug\TraceableHub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Settings writes publish a private change event on the user's settings topic (#89).
 */
class SettingsChangeEventsTest extends ApiTestCase
{
    use SettingsTestTrait;
    use SiteTestTrait;

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->syncSites();
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testUserLayer(): void
    {
        $this->write('PUT', '/users/settings', ['sendCookies' => false]);
        $this->write('PATCH', '/users/settings', ['sendReferrer' => true]);

        $this->assertEvents([
            ['type' => 'settings', 'layer' => 'user', 'action' => 'updated', 'sourceClientId' => null],
            ['type' => 'settings', 'layer' => 'user', 'action' => 'updated', 'sourceClientId' => null],
        ]);
    }

    public function testDownloaderLayer(): void
    {
        $this->write('PUT', '/users/settings/downloaders/yt-dlp-cli', ['enabled' => false]);

        $this->assertEvents([['type' => 'settings', 'layer' => 'downloader', 'action' => 'updated', 'downloader' => 'yt-dlp-cli', 'sourceClientId' => null]]);
    }

    public function testSiteLayer(): void
    {
        $this->write('PUT', '/users/settings/sites/twitter', ['sendCookies' => false]);
        $this->write('DELETE', '/users/settings/sites/twitter');
        // Nothing left to delete: nothing changed, no event
        $this->write('DELETE', '/users/settings/sites/twitter');

        $this->assertEvents([
            ['type' => 'settings', 'layer' => 'site', 'action' => 'updated', 'slug' => 'twitter', 'sourceClientId' => null],
            ['type' => 'settings', 'layer' => 'site', 'action' => 'deleted', 'slug' => 'twitter', 'sourceClientId' => null],
        ]);
    }

    public function testClientLayerNamesTheSourceClient(): void
    {
        $clientId = $this->client->request('POST', '/users/clients', [
            'json' => ['name' => 'Laptop', 'type' => 'browser-extension'],
            'headers' => self::auth() + ['Content-Type' => 'application/ld+json'],
        ])->toArray()['id'];
        $this->hub()->reset();

        $this->write('PUT', "/users/clients/{$clientId}/settings", ['sendCookies' => false], ['X-Client-Id' => $clientId]);
        $this->write('DELETE', "/users/clients/{$clientId}/settings");

        $this->assertEvents([
            ['type' => 'settings', 'layer' => 'client', 'action' => 'updated', 'clientId' => $clientId, 'sourceClientId' => $clientId],
            ['type' => 'settings', 'layer' => 'client', 'action' => 'deleted', 'clientId' => $clientId, 'sourceClientId' => null],
        ]);
    }

    public function testClientPreferencesLayer(): void
    {
        $this->write('PUT', '/users/client-preferences/browser-extension', ['data' => ['theme' => 'dark']]);
        $this->write('DELETE', '/users/client-preferences/browser-extension');

        $this->assertEvents([
            ['type' => 'settings', 'layer' => 'clientPreferences', 'action' => 'updated', 'clientType' => 'browser-extension', 'sourceClientId' => null],
            ['type' => 'settings', 'layer' => 'clientPreferences', 'action' => 'deleted', 'clientType' => 'browser-extension', 'sourceClientId' => null],
        ]);
    }

    public function testFailedWritesPublishNothing(): void
    {
        $this->client->request('PUT', '/users/settings', ['json' => ['preferredDownloader' => 'nope'], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/users/settings/sites/no-such-site', ['json' => [], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(404);

        $this->assertEvents([]);
    }

    /**
     * @param list<array<string, mixed>> $expected the events published on the user's settings topic, in order
     */
    private function assertEvents(array $expected, string $subject = 'user'): void
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $subject]);
        $this->assertNotNull($user);

        $updates = array_map(static fn (array $message): Update => $message['object'], $this->hub()->getMessages());
        $this->assertSame(
            array_map(static fn (array $event): array => ['topics' => [UserTopics::settings($user)], 'private' => true, 'data' => $event], $expected),
            array_map(static fn (Update $update): array => ['topics' => $update->getTopics(), 'private' => $update->isPrivate(), 'data' => json_decode($update->getData(), true)], $updates),
        );
    }

    private function write(string $method, string $path, ?array $json = null, array $headers = []): void
    {
        $contentType = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        $options = ['headers' => self::auth() + $headers + (null === $json ? [] : ['Content-Type' => $contentType])];
        if (null !== $json) {
            $options['json'] = $json;
        }
        $this->client->request($method, $path, $options);
        $this->assertResponseIsSuccessful();
    }

    private function hub(): TraceableHub
    {
        $hub = static::getContainer()->get(HubInterface::class);
        $this->assertInstanceOf(TraceableHub::class, $hub, 'The test environment should use the bundle\'s traceable hub');

        return $hub;
    }
}
