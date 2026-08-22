<?php

namespace App\Tests\Unit\Entity;

use App\Entity\SiteSetting;
use App\Entity\SupportedSite;
use PHPUnit\Framework\TestCase;

class SiteSettingTest extends TestCase
{
    private SiteSetting $siteSetting;
    private SupportedSite $site;

    protected function setUp(): void
    {
        $this->siteSetting = new SiteSetting();
        $this->site = new SupportedSite();
    }

    public function testSetAndGetSite(): void
    {
        $result = $this->siteSetting->setSite($this->site);

        $this->assertSame($this->siteSetting, $result);
        $this->assertSame($this->site, $this->siteSetting->getSite());
    }

    public function testRequiresCookiesWhenTrue(): void
    {
        $this->siteSetting->setSendCookies(true);
        $this->assertTrue($this->siteSetting->requiresCookies());
    }

    public function testRequiresCookiesWhenFalse(): void
    {
        $this->siteSetting->setSendCookies(false);
        $this->assertFalse($this->siteSetting->requiresCookies());
    }

    public function testRequiresCookiesWhenNull(): void
    {
        // Without setting send cookies, it should return false (defaults to false)
        $this->assertFalse($this->siteSetting->requiresCookies());
    }

    public function testRequiresUserAgentWhenTrue(): void
    {
        $this->siteSetting->setSendUserAgent(true);
        $this->assertTrue($this->siteSetting->requiresUserAgent());
    }

    public function testRequiresUserAgentWhenFalse(): void
    {
        $this->siteSetting->setSendUserAgent(false);
        $this->assertFalse($this->siteSetting->requiresUserAgent());
    }

    public function testRequiresUserAgentWhenNull(): void
    {
        $this->assertFalse($this->siteSetting->requiresUserAgent());
    }

    public function testRequiresReferrerWhenTrue(): void
    {
        $this->siteSetting->setSendReferrer(true);
        $this->assertTrue($this->siteSetting->requiresReferrer());
    }

    public function testRequiresReferrerWhenFalse(): void
    {
        $this->siteSetting->setSendReferrer(false);
        $this->assertFalse($this->siteSetting->requiresReferrer());
    }

    public function testRequiresReferrerWhenNull(): void
    {
        $this->assertFalse($this->siteSetting->requiresReferrer());
    }

    public function testInheritsAbstractSiteSettingProperties(): void
    {
        $this->siteSetting
            ->setSite($this->site)
            ->setSendCookies(true)
            ->setSendUserAgent(false)
            ->setSendReferrer(true)
            ->setDownloader('youtube-dl');

        $this->assertSame($this->site, $this->siteSetting->getSite());
        $this->assertTrue($this->siteSetting->isSendCookies());
        $this->assertFalse($this->siteSetting->isSendUserAgent());
        $this->assertTrue($this->siteSetting->isSendReferrer());
        $this->assertEquals('youtube-dl', $this->siteSetting->getDownloader());
    }
}
