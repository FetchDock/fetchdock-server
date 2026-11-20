<?php

namespace App\Tests\Unit\Service\Settings;

use App\Entity\Site;
use App\Entity\SiteSetting;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use App\Service\Settings\EffectiveSettingsResolver;
use PHPUnit\Framework\TestCase;

class EffectiveSettingsResolverTest extends TestCase
{
    private EffectiveSettingsResolver $resolver;

    protected function setUp(): void
    {
        $downloaders = [];
        foreach (['gallery-dl-cli', 'yt-dlp-cli'] as $identifier) {
            $downloader = $this->createStub(DownloaderInterface::class);
            $downloader->method('getIdentifier')->willReturn($identifier);
            $downloaders[$identifier] = $downloader;
        }
        $factory = $this->createStub(DownloaderFactory::class);
        $factory->method('getEnabledDownloaders')->willReturn($downloaders);

        $this->resolver = new EffectiveSettingsResolver($factory);
    }

    public function testHardDefaultsWithoutSite(): void
    {
        $settings = $this->resolver->resolve(null, []);

        $this->assertTrue($settings->sendCookies);
        $this->assertTrue($settings->sendUserAgent);
        $this->assertFalse($settings->sendReferrer);
        $this->assertNull($settings->downloader);
        $this->assertCount(0, $settings->downloaderOptions);
    }

    public function testDefaultDownloaderFollowsFactoryOrder(): void
    {
        $this->assertSame('gallery-dl-cli', $this->resolver->resolve(null, ['yt-dlp-cli', 'gallery-dl-cli'])->downloader);
        $this->assertSame('yt-dlp-cli', $this->resolver->resolve(null, ['yt-dlp-cli'])->downloader);
    }

    public function testSiteDefaultsOverrideHardDefaults(): void
    {
        $site = new Site()->setDefaultSetting(
            new SiteSetting()->setSendCookies(false)->setSendReferrer(true)->setDownloader('yt-dlp-cli')
        );

        $settings = $this->resolver->resolve($site, ['gallery-dl-cli', 'yt-dlp-cli']);

        $this->assertFalse($settings->sendCookies);
        $this->assertTrue($settings->sendUserAgent, 'null inherits the hard default');
        $this->assertTrue($settings->sendReferrer);
        $this->assertSame('yt-dlp-cli', $settings->downloader);
    }

    public function testSiteDownloaderIsIgnoredWhenItCannotHandleTheUrl(): void
    {
        $site = new Site()->setDefaultSetting(new SiteSetting()->setDownloader('yt-dlp-cli'));

        $this->assertSame('gallery-dl-cli', $this->resolver->resolve($site, ['gallery-dl-cli'])->downloader);
    }
}
