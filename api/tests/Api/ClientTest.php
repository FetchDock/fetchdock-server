<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client as HttpClient;
use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class ClientTest extends ApiTestCase
{
    use SettingsTestTrait;
    use SiteTestTrait;

    private HttpClient $http;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->http = static::createClient();
        $this->syncSites();
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testRegisterListRenameAndRevoke(): void
    {
        $id = $this->register('Firefox on laptop');
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue(Uuid::isValid($id));

        $members = $this->http->request('GET', '/users/clients', ['headers' => self::auth()])->toArray()['member'];
        $this->assertCount(1, $members);
        $this->assertSame($id, $members[0]['id']);
        $this->assertSame('Firefox on laptop', $members[0]['name']);
        $this->assertSame('browser-extension', $members[0]['type']);
        $this->assertArrayHasKey('createdAt', $members[0]);
        $this->assertArrayNotHasKey('owner', $members[0]);

        $data = $this->http->request('PATCH', '/users/clients/'.$id, [
            'json' => ['name' => 'Renamed', 'type' => 'other'],
            'headers' => self::auth() + ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertSame('Renamed', $data['name']);
        $this->assertSame('browser-extension', $data['type'], 'the type is set on registration only');

        $this->http->request('DELETE', '/users/clients/'.$id, ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->http->request('GET', '/users/clients/'.$id, ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->http->request('POST', '/users/clients', ['json' => ['name' => 'x', 'type' => 'Not Valid!'], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testClientsAreIsolatedPerOwner(): void
    {
        $id = $this->register('Admin client', 'admin');

        $this->assertSame([], $this->http->request('GET', '/users/clients', ['headers' => self::auth()])->toArray()['member']);
        foreach (['GET' => '/users/clients/'.$id, 'DELETE' => '/users/clients/'.$id] as $method => $path) {
            $this->http->request($method, $path, ['headers' => self::auth()]);
            $this->assertResponseStatusCodeSame(404);
        }
        $this->http->request('GET', '/users/clients/'.$id.'/settings', ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(404);
        $this->http->request('PUT', '/users/clients/'.$id.'/settings', ['json' => ['sendCookies' => false], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testClientSettingsLayer(): void
    {
        $id = $this->register('Phone');

        $this->assertSame(['clientId' => $id, 'sendCookies' => null, 'sendUserAgent' => null, 'sendReferrer' => null, 'preferredDownloader' => null], $this->settings($id));

        $this->http->request('PUT', '/users/clients/'.$id.'/settings', ['json' => ['sendCookies' => false, 'preferredDownloader' => 'yt-dlp-cli'], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['clientId' => $id, 'sendCookies' => false, 'sendUserAgent' => null, 'sendReferrer' => null, 'preferredDownloader' => 'yt-dlp-cli'], $this->settings($id));

        // Applied only when the client identifies itself
        $this->assertTrue($this->resolve()['sendCookies']);
        $withClient = $this->resolve(['X-Client-Id' => $id]);
        $this->assertFalse($withClient['sendCookies']);
        $this->assertSame('yt-dlp-cli', $withClient['downloader']);

        $this->http->request('DELETE', '/users/clients/'.$id.'/settings', ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->settings($id)['sendCookies']);
        $this->assertTrue($this->resolve(['X-Client-Id' => $id])['sendCookies']);
    }

    public function testClientLayerOverridesUserLayers(): void
    {
        $id = $this->register('Phone');
        $this->http->request('PUT', '/users/settings/sites/twitter', ['json' => ['sendReferrer' => true], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);
        $this->http->request('PUT', '/users/clients/'.$id.'/settings', ['json' => ['sendReferrer' => false], 'headers' => self::auth() + ['Content-Type' => 'application/ld+json']]);

        $this->assertTrue($this->resolve()['sendReferrer']);
        $this->assertFalse($this->resolve(['X-Client-Id' => $id])['sendReferrer']);
    }

    public function testForeignOrInvalidClientIdIsIgnored(): void
    {
        $adminClient = $this->register('Admin client', 'admin');
        $this->http->request('PUT', '/users/clients/'.$adminClient.'/settings', ['json' => ['sendCookies' => false], 'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json']]);

        foreach ([$adminClient, Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $clientId) {
            $this->assertTrue($this->resolve(['X-Client-Id' => $clientId])['sendCookies']);
            $this->assertResponseIsSuccessful();
        }
        $this->assertFalse($this->resolve(['X-Client-Id' => $adminClient], 'admin')['sendCookies']);
    }

    public function testLastSeenAtIsTracked(): void
    {
        $id = $this->register('Phone');
        $this->assertNull($this->client($id)->getLastSeenAt());

        $this->http->request('GET', '/users/settings', ['headers' => self::auth() + ['X-Client-Id' => $id]]);

        $this->assertNotNull($this->client($id)->getLastSeenAt());
    }

    private function register(string $name, string $user = 'user'): string
    {
        return $this->http->request('POST', '/users/clients', [
            'json' => ['name' => $name, 'type' => 'browser-extension'],
            'headers' => self::auth($user) + ['Content-Type' => 'application/ld+json'],
        ])->toArray()['id'];
    }

    private function settings(string $id): array
    {
        $data = $this->http->request('GET', '/users/clients/'.$id.'/settings', ['headers' => self::auth()])->toArray();

        return array_filter($data, static fn (string $key): bool => !str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
    }

    private function resolve(array $headers = [], string $user = 'user'): array
    {
        return $this->http->request('GET', '/sites/resolve', [
            'query' => ['url' => 'https://x.com/someone/status/1'],
            'headers' => self::auth($user) + $headers,
        ])->toArray()['effectiveSettings'];
    }

    private function client(string $id): Client
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Client::class, Uuid::fromString($id));
    }
}
