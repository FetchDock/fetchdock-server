<?php

namespace App\Tests\Unit\Entity;

use App\Entity\OidcSubjectIdentifier;
use App\Entity\UserSetting;
use PHPUnit\Framework\TestCase;

class UserSettingTest extends TestCase
{
    private UserSetting $userSetting;

    protected function setUp(): void
    {
        $this->userSetting = new UserSetting();
    }

    public function testConstUriTemplate(): void
    {
        $this->assertEquals('/users/settings.{_format}', UserSetting::URI_TEMPLATE);
    }

    public function testSetAndGetOwner(): void
    {
        $owner = new OidcSubjectIdentifier();
        $result = $this->userSetting->setOwner($owner);

        $this->assertSame($this->userSetting, $result);
        $this->assertSame($owner, $this->userSetting->getOwner());
    }

    public function testSyncBrowsersPropertyIsPublic(): void
    {
        $this->userSetting->syncBrowsers = true;
        $this->assertTrue($this->userSetting->syncBrowsers);

        $this->userSetting->syncBrowsers = false;
        $this->assertFalse($this->userSetting->syncBrowsers);
    }

    public function testChainableSetters(): void
    {
        $owner = new OidcSubjectIdentifier();
        $result = $this->userSetting->setOwner($owner);

        $this->assertSame($this->userSetting, $result);
        $this->assertSame($owner, $this->userSetting->getOwner());
    }
}
