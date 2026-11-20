<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;

class ClientPreferencesTest extends ApiTestCase
{
    use SettingsTestTrait;
    use SiteTestTrait;

    private const string PATH = '/users/client-preferences/browser-extension';

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->resetSettings();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    public function testEmptyWithoutStoredPreferences(): void
    {
        $response = $this->client->request('GET', self::PATH, ['headers' => self::auth()]);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('"data":{}', $response->getContent());
        $this->assertSame(['clientType' => 'browser-extension', 'data' => [], 'updatedAt' => null], self::withoutJsonLd($response->toArray()));
    }

    public function testPutStoresDataAsSent(): void
    {
        $body = '{"data":{"theme":"dark","shortcuts":{},"pinned":[],"nested":{"list":[1,2.5,"x",null,true],"empty":{}}}}';

        $response = $this->put($body);
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($response['updatedAt']);

        $content = $this->client->request('GET', self::PATH, ['headers' => self::auth()])->getContent();
        $this->assertStringContainsString('"data":{"theme":"dark","shortcuts":{},"pinned":[],"nested":{"list":[1,2.5,"x",null,true],"empty":{}}}', $content);
    }

    public function testPutReplacesThePreferences(): void
    {
        $this->put('{"data":{"theme":"dark","sound":true}}');
        $this->put('{"data":{"sound":false}}');

        $this->assertSame(['sound' => false], $this->get()['data']);
    }

    public function testDeleteRemovesThePreferences(): void
    {
        $this->put('{"data":{"theme":"dark"}}');

        $this->client->request('DELETE', self::PATH, ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([], $this->get()['data']);
        $this->assertNull($this->get()['updatedAt']);
    }

    public function testPreferencesAreIsolatedPerOwnerAndClientType(): void
    {
        $this->put('{"data":{"theme":"dark"}}');

        $this->assertSame([], $this->get('admin')['data']);
        $this->assertSame([], self::withoutJsonLd($this->client->request('GET', '/users/client-preferences/android-app', ['headers' => self::auth()])->toArray())['data']);
        $this->assertSame(['theme' => 'dark'], $this->get()['data']);
    }

    public function testBodyLargerThan16KbIsRejected(): void
    {
        $this->put('{"data":{"blob":"'.str_repeat('a', 16 * 1024).'"}}');
        $this->assertResponseStatusCodeSame(413);

        $this->put('{"data":{"blob":"'.str_repeat('a', 16 * 1024 - 30).'"}}');
        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'list' => ['{"data":[1,2]}', 422];
        yield 'string' => ['{"data":"dark"}', 422];
        yield 'null' => ['{"data":null}', 422];
        yield 'missing data' => ['{"theme":"dark"}', 422];
        yield 'not an object' => ['[]', 422];
        yield 'invalid JSON' => ['{"data":', 400];
        yield 'too deep' => ['{"data":'.str_repeat('{"a":', 40).'1'.str_repeat('}', 40).'}', 400];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRejected(string $body, int $status): void
    {
        $this->put($body);

        $this->assertResponseStatusCodeSame($status);
        $this->assertSame([], $this->get()['data'], 'nothing was stored');
    }

    public function testInvalidClientTypeIsNotFound(): void
    {
        foreach (['/users/client-preferences/Browser', '/users/client-preferences/'.str_repeat('a', 33), '/users/client-preferences/a_b'] as $path) {
            $this->client->request('GET', $path, ['headers' => self::auth()]);
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', self::PATH);
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('PUT', self::PATH, ['body' => '{"data":{}}', 'headers' => ['Content-Type' => 'application/json']]);
        $this->assertResponseStatusCodeSame(401);
    }

    private function get(string $user = 'user'): array
    {
        return self::withoutJsonLd($this->client->request('GET', self::PATH, ['headers' => self::auth($user)])->toArray());
    }

    private function put(string $body, string $user = 'user'): array
    {
        return self::withoutJsonLd($this->client->request('PUT', self::PATH, ['body' => $body, 'headers' => self::auth($user) + ['Content-Type' => 'application/json']])->toArray(false));
    }

    private static function withoutJsonLd(array $data): array
    {
        return array_filter($data, static fn ($key) => !is_string($key) || !str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
    }
}
