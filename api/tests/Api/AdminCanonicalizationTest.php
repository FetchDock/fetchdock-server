<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\SiteCanonicalizationOverride;
use App\Service\Canonical\CanonicalProfileRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * #94 phase 2: admin overrides of sites' canonicalization profiles, and the canonicalize debug endpoint.
 */
class AdminCanonicalizationTest extends ApiTestCase
{
    use SiteTestTrait;

    private const string URI = '/admin/sites/vimeo/canonicalization';

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
        $this->removeOverrides();
    }

    protected function tearDown(): void
    {
        $this->removeOverrides();
        parent::tearDown();
    }

    public function testGetWithoutOverrideInheritsEverything(): void
    {
        $data = $this->request('GET', self::URI)->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('vimeo', $data['slug']);
        $this->assertNull($data['profile']);
        $this->assertNull($data['useExtractorId']);
        $this->assertNull($data['dropParams']);
        $this->assertSame('vimeo', $data['effective']['profile']);
        $this->assertTrue($data['effective']['useExtractorId']);
        $this->assertContains('share', $data['effective']['dropParams']);
        $this->assertContains('default', $data['availableProfiles']);
    }

    public function testOverrideChangesTheKeyOfNewSubmissions(): void
    {
        $url = 'https://vimeo.com/76979871?share=copy&ref=feed';
        $this->assertSame(
            ['key' => 'ytdlp:Vimeo:76979871', 'layer' => 'ytdlp', 'profile' => 'vimeo', 'site' => 'vimeo'],
            $this->canonicalize($url),
        );

        $data = $this->request('PUT', self::URI, ['useExtractorId' => false, 'dropParams' => ['ref']])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertFalse($data['useExtractorId']);
        $this->assertSame(['ref'], $data['dropParams']);
        $this->assertFalse($data['effective']['useExtractorId']);
        $this->assertContains('ref', $data['effective']['dropParams']);
        $this->assertContains('share', $data['effective']['dropParams']);

        $this->assertSame(
            ['key' => 'url:https://vimeo.com/76979871', 'layer' => 'url', 'profile' => 'vimeo', 'site' => 'vimeo'],
            $this->canonicalize($url),
        );

        // A submission uses the override too: the variant without the dropped parameter is a duplicate
        $videoUrl = 'https://vimeo.com/'.random_int(100000000, 999999999);
        $this->request('POST', '/download_jobs', ['uri' => $videoUrl.'?ref=a', 'downloader' => 'mock']);
        $this->assertResponseStatusCodeSame(202);
        $this->request('POST', '/download_jobs', ['uri' => $videoUrl, 'downloader' => 'mock']);
        $this->assertResponseStatusCodeSame(409);

        $this->request('DELETE', self::URI);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('ytdlp:Vimeo:76979871', $this->canonicalize($url)['key']);
        $this->assertNull($this->request('GET', self::URI)->toArray()['useExtractorId']);
    }

    public function testProfileOverrideSwitchesTheBaseProfile(): void
    {
        $data = $this->request('PUT', self::URI, ['profile' => 'reddit'])->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('reddit', $data['effective']['profile']);
        $this->assertTrue($data['effective']['stripTrailingSlash']);
    }

    public static function invalidOverrides(): iterable
    {
        yield 'unknown profile' => [['profile' => 'nope'], 422];
        yield 'too many patterns' => [['dropParams' => array_map(static fn (int $i): string => 'p'.$i, range(1, 51))], 422];
        yield 'blank pattern' => [['keepParams' => ['']], 422];
        yield 'invalid idGroups' => [['idGroups' => ['tweet' => [-1]]], 422];
        yield 'pathRewrites are code only' => [['pathRewrites' => [['pattern' => '~a~', 'replacement' => 'b']]], 400];
    }

    #[DataProvider('invalidOverrides')]
    public function testRejectsInvalidOverrides(array $payload, int $status): void
    {
        $this->request('PUT', self::URI, $payload);

        $this->assertResponseStatusCodeSame($status);
        $this->assertNull($this->request('GET', self::URI)->toArray()['effective']['keepParams']);
    }

    public function testUnknownSiteIs404(): void
    {
        $this->request('GET', '/admin/sites/no-such-site/canonicalization');
        $this->assertResponseStatusCodeSame(404);

        $this->request('PUT', '/admin/sites/no-such-site/canonicalization', ['stripWww' => true]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCanonicalizeRequiresAValidUrl(): void
    {
        $this->request('GET', '/admin/canonicalize?url=not-a-url');
        $this->assertResponseStatusCodeSame(422);
    }

    public static function adminOperations(): iterable
    {
        yield 'get override' => ['GET', self::URI];
        yield 'put override' => ['PUT', self::URI];
        yield 'delete override' => ['DELETE', self::URI];
        yield 'canonicalize' => ['GET', '/admin/canonicalize?url=https://vimeo.com/1'];
    }

    #[DataProvider('adminOperations')]
    public function testNonAdminsAreForbidden(string $method, string $uri): void
    {
        $this->client->request($method, $uri, [
            'json' => 'PUT' === $method ? ['stripWww' => true] : null,
            'headers' => ['Content-Type' => 'application/ld+json', ...self::auth('user')],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{key: string, layer: string, profile: string, site: ?string}
     */
    private function canonicalize(string $url): array
    {
        $data = $this->request('GET', '/admin/canonicalize?url='.rawurlencode($url))->toArray();
        $this->assertResponseIsSuccessful();

        return array_intersect_key($data, array_flip(['key', 'layer', 'profile', 'site']));
    }

    private function request(string $method, string $uri, ?array $json = null): ResponseInterface
    {
        return $this->client->request($method, $uri, [
            'json' => $json,
            'headers' => ['Content-Type' => 'application/ld+json', ...self::auth('admin')],
        ]);
    }

    private function removeOverrides(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.SiteCanonicalizationOverride::class)->execute();
        static::getContainer()->get(CanonicalProfileRegistry::class)->invalidate();
    }
}
