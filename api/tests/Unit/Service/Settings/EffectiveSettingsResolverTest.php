<?php

namespace App\Tests\Unit\Service\Settings;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\OidcSubjectIdentifier;
use App\Entity\Site;
use App\Entity\SiteSetting;
use App\Entity\UserDownloaderSetting;
use App\Entity\UserSetting;
use App\Entity\UserSiteSetting;
use App\Factory\DownloaderFactory;
use App\Repository\UserDownloaderSettingRepository;
use App\Repository\UserSettingRepository;
use App\Repository\UserSiteSettingRepository;
use App\Service\Downloader\DownloaderInterface;
use App\Service\Settings\EffectiveSettingsResolver;
use PHPUnit\Framework\TestCase;

class EffectiveSettingsResolverTest extends TestCase
{
    private const array BOTH = ['gallery-dl-cli', 'yt-dlp-cli'];

    private ?UserSetting $userSetting = null;
    private ?UserSiteSetting $userSiteSetting = null;
    /** @var list<UserDownloaderSetting> */
    private array $downloaderSettings = [];
    private OidcSubjectIdentifier $owner;
    private Site $site;

    protected function setUp(): void
    {
        $this->owner = new OidcSubjectIdentifier();
        $this->site = new Site();
        // Only managed sites (with an id) have user overrides
        new \ReflectionProperty(Site::class, 'id')->setValue($this->site, 1);
    }

    public function testHardDefaultsWithoutSite(): void
    {
        $settings = $this->resolver()->resolve(null, []);

        $this->assertTrue($settings->sendCookies);
        $this->assertTrue($settings->sendUserAgent);
        $this->assertFalse($settings->sendReferrer);
        $this->assertNull($settings->downloader);
        $this->assertCount(0, $settings->downloaderOptions);
    }

    public function testDefaultDownloaderFollowsFactoryOrder(): void
    {
        $this->assertSame('gallery-dl-cli', $this->resolver()->resolve(null, ['yt-dlp-cli', 'gallery-dl-cli'])->downloader);
        $this->assertSame('yt-dlp-cli', $this->resolver()->resolve(null, ['yt-dlp-cli'])->downloader);
    }

    public function testSiteDefaultsOverrideHardDefaults(): void
    {
        $this->site->setDefaultSetting(new SiteSetting()->setSendCookies(false)->setSendReferrer(true)->setDownloader('yt-dlp-cli'));

        $settings = $this->resolver()->resolve($this->site, self::BOTH);

        $this->assertFalse($settings->sendCookies);
        $this->assertTrue($settings->sendUserAgent, 'null inherits the hard default');
        $this->assertTrue($settings->sendReferrer);
        $this->assertSame('yt-dlp-cli', $settings->downloader);
    }

    public function testSiteDownloaderIsIgnoredWhenItCannotHandleTheUrl(): void
    {
        $this->site->setDefaultSetting(new SiteSetting()->setDownloader('yt-dlp-cli'));

        $this->assertSame('gallery-dl-cli', $this->resolver()->resolve($this->site, ['gallery-dl-cli'])->downloader);
    }

    public function testLayerPrecedence(): void
    {
        $client = new Client()->setOwner($this->owner);
        $this->site->setDefaultSetting(new SiteSetting()->setSendCookies(false)->setSendUserAgent(false)->setSendReferrer(false));
        $this->userSetting = new UserSetting()->setSendCookies(true)->setSendUserAgent(true)->setSendReferrer(true);
        $this->userSiteSetting = new UserSiteSetting()->setSendUserAgent(false);
        $client->setSetting(new ClientSetting()->setSendReferrer(false));

        $settings = $this->resolver()->resolve($this->site, self::BOTH, $this->owner, $client);

        $this->assertTrue($settings->sendCookies, 'user layer overrides site defaults');
        $this->assertFalse($settings->sendUserAgent, 'user site layer overrides user layer');
        $this->assertFalse($settings->sendReferrer, 'client layer overrides all');
    }

    public function testDownloaderPreferencePrecedence(): void
    {
        $client = new Client()->setOwner($this->owner);
        $this->site->setDefaultSetting(new SiteSetting()->setDownloader('yt-dlp-cli'));
        $this->userSetting = new UserSetting()->setPreferredDownloader('gallery-dl-cli');
        $this->assertSame('gallery-dl-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner)->downloader);

        $this->userSiteSetting = new UserSiteSetting()->setDownloader('yt-dlp-cli');
        $this->assertSame('yt-dlp-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner)->downloader);

        $client->setSetting(new ClientSetting()->setPreferredDownloader('gallery-dl-cli'));
        $this->assertSame('gallery-dl-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner, $client)->downloader);
    }

    public function testForeignClientIsIgnored(): void
    {
        $client = new Client()->setOwner(new OidcSubjectIdentifier());
        $client->setSetting(new ClientSetting()->setSendCookies(false));

        $this->assertTrue($this->resolver()->resolve($this->site, self::BOTH, $this->owner, $client)->sendCookies);
    }

    public function testDisabledDownloaderIsNeverChosen(): void
    {
        $this->downloaderSettings = [new UserDownloaderSetting()->setDownloader('gallery-dl-cli')->setEnabled(false)];
        $this->userSetting = new UserSetting()->setPreferredDownloader('gallery-dl-cli');

        $this->assertSame('yt-dlp-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner)->downloader);
        $this->assertNull($this->resolver()->resolve($this->site, ['gallery-dl-cli'], $this->owner)->downloader);
        $this->assertFalse($this->resolver()->isDownloaderEnabled('gallery-dl-cli', $this->owner));
        $this->assertTrue($this->resolver()->isDownloaderEnabled('yt-dlp-cli', $this->owner));
        $this->assertFalse($this->resolver()->isDownloaderEnabled('unknown', $this->owner));
    }

    public function testPriorityOrdersDownloadersWithoutPreference(): void
    {
        $this->downloaderSettings = [
            new UserDownloaderSetting()->setDownloader('yt-dlp-cli')->setPriority(1),
            new UserDownloaderSetting()->setDownloader('gallery-dl-cli')->setPriority(2),
        ];

        $this->assertSame('yt-dlp-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner)->downloader);

        $this->downloaderSettings = [new UserDownloaderSetting()->setDownloader('yt-dlp-cli')->setPriority(null)];
        $this->assertSame('gallery-dl-cli', $this->resolver()->resolve($this->site, self::BOTH, $this->owner)->downloader, 'null priority keeps the server order');
    }

    private function resolver(): EffectiveSettingsResolver
    {
        $downloaders = [];
        foreach (self::BOTH as $identifier) {
            $downloader = $this->createStub(DownloaderInterface::class);
            $downloader->method('getIdentifier')->willReturn($identifier);
            $downloaders[$identifier] = $downloader;
        }
        $factory = $this->createStub(DownloaderFactory::class);
        $factory->method('getEnabledDownloaders')->willReturn($downloaders);

        $userSettings = $this->createStub(UserSettingRepository::class);
        $userSettings->method('findOneBy')->willReturn($this->userSetting);
        $userSiteSettings = $this->createStub(UserSiteSettingRepository::class);
        $userSiteSettings->method('findOneBy')->willReturn($this->userSiteSetting);
        $downloaderSettings = $this->createStub(UserDownloaderSettingRepository::class);
        $downloaderSettings->method('findBy')->willReturn($this->downloaderSettings);

        return new EffectiveSettingsResolver($factory, $userSettings, $downloaderSettings, $userSiteSettings);
    }
}
