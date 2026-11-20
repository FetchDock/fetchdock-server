<?php

namespace App\Tests\Unit\Entity;

use App\Entity\OidcSubjectIdentifier;
use App\Entity\Site;
use App\Entity\UserSiteSetting;
use PHPUnit\Framework\TestCase;

class UserSiteSettingTest extends TestCase
{
    public function testHasASingleOwner(): void
    {
        $owner = new OidcSubjectIdentifier();
        $setting = new UserSiteSetting()->setSite(new Site()->setSlug('pixiv')->setName('pixiv'));

        $owner->addUserSiteSetting($setting);

        $this->assertSame($owner, $setting->getOwner());
        $this->assertCount(1, $owner->getUserSiteSettings());

        // Adding twice doesn't duplicate
        $owner->addUserSiteSetting($setting);
        $this->assertCount(1, $owner->getUserSiteSettings());

        $owner->removeUserSiteSetting($setting);
        $this->assertCount(0, $owner->getUserSiteSettings());
    }
}
