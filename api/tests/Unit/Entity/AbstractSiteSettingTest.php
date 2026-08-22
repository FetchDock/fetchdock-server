<?php

namespace App\Tests\Unit\Entity;

use App\Entity\AbstractSiteSetting;
use App\Entity\SupportedSite;
use App\Entity\UserSiteSetting;
use PHPUnit\Framework\TestCase;

class AbstractSiteSettingTest extends TestCase
{
    private AbstractSiteSetting $abstractSiteSetting;
    private SupportedSite $site;

    protected function setUp(): void
    {
        // Use UserSiteSetting as concrete implementation of AbstractSiteSetting
        $this->abstractSiteSetting = new UserSiteSetting();
        $this->site = new SupportedSite();
    }

    public function testGetIdReturnsNull(): void
    {
        $this->assertNull($this->abstractSiteSetting->getId());
    }

    public function testSetAndGetSite(): void
    {
        $result = $this->abstractSiteSetting->setSite($this->site);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertSame($this->site, $this->abstractSiteSetting->getSite());
    }

    public function testSetAndGetSendCookies(): void
    {
        $result = $this->abstractSiteSetting->setSendCookies(true);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertTrue($this->abstractSiteSetting->isSendCookies());
    }

    public function testSetAndGetSendCookiesFalse(): void
    {
        $result = $this->abstractSiteSetting->setSendCookies(false);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertFalse($this->abstractSiteSetting->isSendCookies());
    }

    public function testSetAndGetSendUserAgent(): void
    {
        $result = $this->abstractSiteSetting->setSendUserAgent(true);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertTrue($this->abstractSiteSetting->isSendUserAgent());
    }

    public function testSetAndGetSendUserAgentFalse(): void
    {
        $result = $this->abstractSiteSetting->setSendUserAgent(false);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertFalse($this->abstractSiteSetting->isSendUserAgent());
    }

    public function testSetAndGetSendReferrer(): void
    {
        $result = $this->abstractSiteSetting->setSendReferrer(true);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertTrue($this->abstractSiteSetting->isSendReferrer());
    }

    public function testSetAndGetSendReferrerFalse(): void
    {
        $result = $this->abstractSiteSetting->setSendReferrer(false);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertFalse($this->abstractSiteSetting->isSendReferrer());
    }

    public function testSetAndGetDownloader(): void
    {
        $downloader = 'youtube-dl';
        $result = $this->abstractSiteSetting->setDownloader($downloader);

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertSame($downloader, $this->abstractSiteSetting->getDownloader());
    }

    public function testChainableSetters(): void
    {
        $result = $this->abstractSiteSetting
            ->setSite($this->site)
            ->setSendCookies(true)
            ->setSendUserAgent(false)
            ->setSendReferrer(true)
            ->setDownloader('gallery-dl');

        $this->assertSame($this->abstractSiteSetting, $result);
        $this->assertSame($this->site, $this->abstractSiteSetting->getSite());
        $this->assertTrue($this->abstractSiteSetting->isSendCookies());
        $this->assertFalse($this->abstractSiteSetting->isSendUserAgent());
        $this->assertTrue($this->abstractSiteSetting->isSendReferrer());
        $this->assertEquals('gallery-dl', $this->abstractSiteSetting->getDownloader());
    }
}
