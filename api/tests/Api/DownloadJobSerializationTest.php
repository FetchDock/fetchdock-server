<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Dto\CookieDTO;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Sensitive DownloadJob data must never be serialized: not in API responses, and not in the
 * Mercure updates that API Platform publishes for the resource (they use the same serializer).
 */
class DownloadJobSerializationTest extends ApiTestCase
{
    private const array SENSITIVE_FIELDS = ['cookies', 'cookiesNetscapeFileContent', 'token', 'owner', 'userAgent'];

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
    }

    public function testApiResponseDoesNotContainSensitiveFields(): void
    {
        $client = static::createClient();
        $job = $this->createJobWithCookie('admin');

        $response = $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122(), [
            'headers' => ['Authorization' => 'Basic '.base64_encode('admin:adminpass')],
        ]);

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();
        $this->assertSame($job->getUri(), $data['uri']);
        $this->assertSame($job->getUuid()->toRfc4122(), $data['uuid']);
        foreach (self::SENSITIVE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data, "API response exposes '{$field}'");
        }
        $this->assertStringNotContainsString('FAKE_COOKIE_VALUE', $response->getContent());
    }

    public function testJsonLdSerializationUsedForMercureUpdatesDoesNotContainSensitiveFields(): void
    {
        static::createClient();
        $job = $this->createJobWithCookie('admin');

        $json = static::getContainer()->get(SerializerInterface::class)->serialize($job, 'jsonld');

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($job->getUri(), $data['uri']);
        foreach (self::SENSITIVE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data, "JSON-LD serialization exposes '{$field}'");
        }
        $this->assertStringNotContainsString('FAKE_COOKIE_VALUE', $json);
        $this->assertStringNotContainsString($job->getToken(), $json);
    }

    private function createJobWithCookie(string $ownerSubject): DownloadJob
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $ownerSubject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$ownerSubject}', run `make prep-test`");

        $cookie = new CookieDTO();
        $cookie->name = 'session';
        $cookie->value = 'FAKE_COOKIE_VALUE';
        $cookie->domain = '.example.com';
        $cookie->path = '/';
        $cookie->hostOnly = false;
        $cookie->httpOnly = true;
        $cookie->sameSite = 'lax';

        $job = new DownloadJob()
            ->setUri('https://example.com/serialization-test-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::PENDING)
            ->setOwner($owner)
            ->setCookies([$cookie])
            ->setUserAgent('FakeAgent/1.0');
        $entityManager->persist($job);
        $entityManager->flush();

        return $job;
    }
}
