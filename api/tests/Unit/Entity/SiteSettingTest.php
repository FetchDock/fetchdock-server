<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Site;
use App\Entity\SiteSetting;
use PHPUnit\Framework\TestCase;

class SiteSettingTest extends TestCase
{
    public function testSiteDefaultSettingLinksBothSides(): void
    {
        $site = new Site()->setSlug('youtube')->setName('YouTube');
        $setting = new SiteSetting();

        $site->setDefaultSetting($setting);

        $this->assertSame($setting, $site->getDefaultSetting());
        $this->assertSame($site, $setting->getSite());
    }
}
