<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

class DownloadJobTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
    }

    public function testCreateDownloadJobWithFullPayload(): void
    {
        static::createClient()->request('POST', '/download_jobs', [
            'json' => [
                'uri' => 'https://example.com/file.zip',
                'userAgent' => 'MyDownloader/1.0',
                'cookies' => [
                    'session' => 'abc123',
                ],
                'downloader' => 'mock',
                'force' => true
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Basic '.base64_encode('admin:adminpass'),
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    public function testCreateDownloadJobWithMinimalPayload(): void
    {
        static::createClient()->request('POST', '/download_jobs', [
            'json' => [
                'uri' => 'https://example.com/file.zip',
                'downloader' => 'mock',
                'force' => true
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Basic '.base64_encode('admin:adminpass'),
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }
}
