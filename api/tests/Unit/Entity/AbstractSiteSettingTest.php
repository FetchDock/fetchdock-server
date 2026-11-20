<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Site;
use App\Entity\SiteSetting;
use App\Entity\UserSiteSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbstractSiteSettingTest extends TestCase
{
    public static function settingClasses(): iterable
    {
        yield 'site defaults' => [SiteSetting::class];
        yield 'user overrides' => [UserSiteSetting::class];
    }

    #[DataProvider('settingClasses')]
    public function testAllFieldsDefaultToNullMeaningInherit(string $class): void
    {
        $setting = new $class();

        $this->assertNull($setting->getId());
        $this->assertNull($setting->getSite());
        $this->assertNull($setting->isSendCookies());
        $this->assertNull($setting->isSendUserAgent());
        $this->assertNull($setting->isSendReferrer());
        $this->assertNull($setting->getDownloader());
    }

    #[DataProvider('settingClasses')]
    public function testSettersAreChainableAndAcceptNull(string $class): void
    {
        $site = new Site()->setSlug('twitter')->setName('Twitter / X');
        $setting = new $class()
            ->setSite($site)
            ->setSendCookies(true)
            ->setSendUserAgent(false)
            ->setSendReferrer(true)
            ->setDownloader('gallery-dl-cli');

        $this->assertSame($site, $setting->getSite());
        $this->assertTrue($setting->isSendCookies());
        $this->assertFalse($setting->isSendUserAgent());
        $this->assertTrue($setting->isSendReferrer());
        $this->assertSame('gallery-dl-cli', $setting->getDownloader());

        $setting->setSendCookies(null)->setSendUserAgent(null)->setSendReferrer(null)->setDownloader(null);

        $this->assertNull($setting->isSendCookies());
        $this->assertNull($setting->isSendUserAgent());
        $this->assertNull($setting->isSendReferrer());
        $this->assertNull($setting->getDownloader());
    }
}
