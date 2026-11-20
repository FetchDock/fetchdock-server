<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use App\Factory\DownloaderFactory;
use App\Model\EffectiveSettings;
use App\Security\CurrentOwner;
use App\Service\Settings\CurrentClient;
use App\Service\Settings\EffectiveSettingsResolver;
use App\Service\Site\SiteResolver;
use App\State\ResolveSiteProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

class ResolveSiteProviderTest extends TestCase
{
    private const string URL = 'https://example.com/page';

    public function testUnsupportedUrlResolvesWithoutSite(): void
    {
        $resolution = $this->createProvider(limit: 5)->provide(new Get(), [], ['request' => $this->request()]);

        $this->assertSame(self::URL, $resolution->url);
        $this->assertSame('example.com', $resolution->host);
        $this->assertNull($resolution->site);
        $this->assertSame([], $resolution->extractors);
        $this->assertTrue($resolution->requestContext->cookies);
        $this->assertFalse($resolution->requestContext->referrer);
    }

    public function testRateLimitPerUser(): void
    {
        $provider = $this->createProvider(limit: 2);

        $provider->provide(new Get(), [], ['request' => $this->request()]);
        $provider->provide(new Get(), [], ['request' => $this->request()]);

        $this->expectException(TooManyRequestsHttpException::class);
        $provider->provide(new Get(), [], ['request' => $this->request()]);
    }

    public function testMatcherFailureIsAServiceUnavailableError(): void
    {
        $siteResolver = $this->createStub(SiteResolver::class);
        $siteResolver->method('resolve')->willThrowException(new \RuntimeException('helper failed'));

        $this->expectException(ServiceUnavailableHttpException::class);
        $this->createProvider(limit: 5, siteResolver: $siteResolver)->provide(new Get(), [], ['request' => $this->request()]);
    }

    private function createProvider(int $limit, ?SiteResolver $siteResolver = null): ResolveSiteProvider
    {
        if (null === $siteResolver) {
            $siteResolver = $this->createStub(SiteResolver::class);
            $siteResolver->method('resolve')->willReturn(null);
        }
        $settingsResolver = $this->createStub(EffectiveSettingsResolver::class);
        $settingsResolver->method('resolve')->willReturn(new EffectiveSettings(true, true, false, null));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('user', null));

        return new ResolveSiteProvider(
            $siteResolver,
            $settingsResolver,
            $this->createStub(DownloaderFactory::class),
            $security,
            new RateLimiterFactory(['id' => 'site_resolve', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
            $this->createStub(CurrentOwner::class),
            $this->createStub(CurrentClient::class),
        );
    }

    private function request(): Request
    {
        return Request::create('/sites/resolve', 'GET', ['url' => self::URL]);
    }
}
