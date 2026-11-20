<?php

namespace App\Tests\Unit\Entity;

use App\Entity\OidcSubjectIdentifier;
use App\Entity\UserSetting;
use PHPUnit\Framework\TestCase;

class UserSettingTest extends TestCase
{
    public function testSetAndGetOwner(): void
    {
        $userSetting = new UserSetting();
        $owner = new OidcSubjectIdentifier();

        $this->assertNull($userSetting->getId());
        $this->assertSame($userSetting, $userSetting->setOwner($owner));
        $this->assertSame($owner, $userSetting->getOwner());
    }

    public function testSyncBrowsersWasRemoved(): void
    {
        $this->assertFalse(property_exists(UserSetting::class, 'syncBrowsers'));
    }
}
