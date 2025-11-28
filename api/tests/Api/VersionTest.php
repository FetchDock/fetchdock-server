<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

class VersionTest extends ApiTestCase
{
    public function testGetVersionsCollection(): void
    {
        $response = static::createClient()->request('GET', '/versions');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $data = $response->toArray();

        // Verify JSON-LD structure
        $this->assertArrayHasKey('member', $data);

        // If there are versions, verify each has the expected fields
        foreach ($data['member'] as $version) {
            $this->assertArrayHasKey('@id', $version);
            $this->assertArrayHasKey('@type', $version);
            $this->assertArrayHasKey('id', $version);
            $this->assertArrayHasKey('version', $version);
            $this->assertArrayHasKey('currentVersion', $version);
            $this->assertArrayHasKey('latestVersion', $version);

            // Verify types
            $this->assertIsString($version['id']);
            $this->assertIsString($version['version']);
            $this->assertIsString($version['currentVersion']);
            $this->assertIsString($version['latestVersion']);
        }
    }

    public function testGetSingleVersionWithMockDownloader(): void
    {
        $response = static::createClient()->request('GET', '/versions/mock');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $data = $response->toArray();

        // Verify structure
        $this->assertArrayHasKey('@id', $data);
        $this->assertArrayHasKey('@type', $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('currentVersion', $data);
        $this->assertArrayHasKey('latestVersion', $data);

        // Verify mock downloader data
        $this->assertSame('mock', $data['id']);
        $this->assertSame('1.0.0-mock', $data['currentVersion']);
        $this->assertSame('1.0.0-mock', $data['latestVersion']);

        // Verify deprecated version field matches currentVersion
        $this->assertSame($data['currentVersion'], $data['version']);
    }

    public function testGetNonExistentVersionReturns404(): void
    {
        static::createClient()->request('GET', '/versions/nonexistent-downloader');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testVersionsCollectionIncludesLatestVersionField(): void
    {
        $response = static::createClient()->request('GET', '/versions');

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();

        // Specifically verify latestVersion is present and correctly populated
        $this->assertArrayHasKey('member', $data);

        // Find the mock downloader in the collection
        $mockFound = false;
        foreach ($data['member'] as $version) {
            if ($version['id'] === 'mock') {
                $mockFound = true;
                // Verify latestVersion is correctly populated from downloader
                $this->assertArrayHasKey('latestVersion', $version);
                $this->assertSame('1.0.0-mock', $version['latestVersion']);
                break;
            }
        }

        // Assert mock downloader was found (should always be present in test environment)
        $this->assertTrue($mockFound, 'Mock downloader should be present in versions collection');
    }

    public function testVersionResponseContainsAllVersionFields(): void
    {
        $response = static::createClient()->request('GET', '/versions/mock', [
            'headers' => [
                'Accept' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();

        // Verify all version-related fields are present in the response
        $this->assertArrayHasKey('version', $data, 'Deprecated version field should be present');
        $this->assertArrayHasKey('currentVersion', $data, 'currentVersion field should be present');
        $this->assertArrayHasKey('latestVersion', $data, 'latestVersion field should be present');

        // Verify the relationship between fields
        $this->assertSame($data['version'], $data['currentVersion'], 'version should equal currentVersion');
    }
}
