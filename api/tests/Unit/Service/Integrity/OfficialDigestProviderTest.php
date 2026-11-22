<?php

namespace App\Tests\Unit\Service\Integrity;

use App\Service\Integrity\OfficialDigestProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OfficialDigestProviderTest extends TestCase
{
    private const string DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testFetchesAndCachesTheDigestOfTheVersion(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse(strtoupper(self::DIGEST)."  integrity-manifest.json\n");
        });
        $provider = new OfficialDigestProvider($client, new ArrayAdapter(), new NullLogger());

        self::assertSame(self::DIGEST, $provider->fetch('0.9.1-dev.abc1234', self::DIGEST));
        self::assertSame(self::DIGEST, $provider->fetch('0.9.1-dev.abc1234', self::DIGEST));
        self::assertSame(['https://raw.githubusercontent.com/FetchDock/fetchdock-server/integrity-data/0.9.1-dev.abc1234.sha256'], $requests);
    }

    public function testMissingDigestIsNotCached(): void
    {
        $responses = [new MockResponse('Not Found', ['http_code' => 404]), new MockResponse(self::DIGEST)];
        $provider = new OfficialDigestProvider(new MockHttpClient($responses), new ArrayAdapter(), new NullLogger());

        self::assertNull($provider->fetch('0.9.0', self::DIGEST));
        self::assertSame(self::DIGEST, $provider->fetch('0.9.0', self::DIGEST));
    }

    public function testNetworkErrorsAndInvalidContentAreUnknown(): void
    {
        $responses = [new MockResponse('', ['error' => 'Could not resolve host']), new MockResponse('<html>')];
        $provider = new OfficialDigestProvider(new MockHttpClient($responses), new ArrayAdapter(), new NullLogger());

        self::assertNull($provider->fetch('0.9.0', self::DIGEST));
        self::assertNull($provider->fetch('0.9.0', self::DIGEST));
    }

    public function testLocalBuildsAreNotLookedUp(): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('No request expected'));
        $provider = new OfficialDigestProvider($client, new ArrayAdapter(), new NullLogger());

        self::assertNull($provider->fetch('dev', self::DIGEST));
        self::assertNull($provider->fetch('undefined', self::DIGEST));
    }
}
