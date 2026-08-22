<?php

namespace App\Tests\Unit\Entity;

use App\Entity\OidcSubjectIdentifier;
use App\Entity\SupportedSite;
use App\Entity\UserSiteSetting;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

class UserSiteSettingTest extends TestCase
{
    private UserSiteSetting $userSiteSetting;

    protected function setUp(): void
    {
        $this->userSiteSetting = new UserSiteSetting();
    }

    public function testConstructorInitializesOwnerCollection(): void
    {
        $this->assertInstanceOf(Collection::class, $this->userSiteSetting->getOwner());
        $this->assertCount(0, $this->userSiteSetting->getOwner());
    }

    public function testSetAndGetSite(): void
    {
        $site = new SupportedSite();
        $result = $this->userSiteSetting->setSite($site);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertSame($site, $this->userSiteSetting->getSite());
    }

    public function testAddOwner(): void
    {
        $owner = new OidcSubjectIdentifier();
        $result = $this->userSiteSetting->addOwner($owner);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertCount(1, $this->userSiteSetting->getOwner());
        $this->assertTrue($this->userSiteSetting->getOwner()->contains($owner));
    }

    public function testAddOwnerDuplicateNotAdded(): void
    {
        $owner = new OidcSubjectIdentifier();
        $this->userSiteSetting->addOwner($owner);
        $this->userSiteSetting->addOwner($owner);

        $this->assertCount(1, $this->userSiteSetting->getOwner());
    }

    public function testRemoveOwner(): void
    {
        $owner = new OidcSubjectIdentifier();
        $this->userSiteSetting->addOwner($owner);
        $this->assertCount(1, $this->userSiteSetting->getOwner());

        $result = $this->userSiteSetting->removeOwner($owner);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertCount(0, $this->userSiteSetting->getOwner());
    }

    public function testRemoveNonExistentOwnerIsNoOp(): void
    {
        $owner = new OidcSubjectIdentifier();
        $result = $this->userSiteSetting->removeOwner($owner);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertCount(0, $this->userSiteSetting->getOwner());
    }

    public function testSetAndGetSendCookies(): void
    {
        $result = $this->userSiteSetting->setSendCookies(true);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertTrue($this->userSiteSetting->isSendCookies());
    }

    public function testSetAndGetSendUserAgent(): void
    {
        $result = $this->userSiteSetting->setSendUserAgent(false);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertFalse($this->userSiteSetting->isSendUserAgent());
    }

    public function testSetAndGetSendReferrer(): void
    {
        $result = $this->userSiteSetting->setSendReferrer(true);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertTrue($this->userSiteSetting->isSendReferrer());
    }

    public function testSetAndGetDownloader(): void
    {
        $downloader = 'youtube-dl';
        $result = $this->userSiteSetting->setDownloader($downloader);

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertSame($downloader, $this->userSiteSetting->getDownloader());
    }

    public function testChainableSetters(): void
    {
        $site = new SupportedSite();
        $result = $this->userSiteSetting
            ->setSite($site)
            ->setSendCookies(true)
            ->setSendUserAgent(false)
            ->setSendReferrer(true)
            ->setDownloader('gallery-dl');

        $this->assertSame($this->userSiteSetting, $result);
        $this->assertTrue($this->userSiteSetting->isSendCookies());
        $this->assertFalse($this->userSiteSetting->isSendUserAgent());
    }

    public function testMultipleOwners(): void
    {
        $owner1 = new OidcSubjectIdentifier();
        $owner2 = new OidcSubjectIdentifier();
        $owner3 = new OidcSubjectIdentifier();

        $this->userSiteSetting->addOwner($owner1);
        $this->userSiteSetting->addOwner($owner2);
        $this->userSiteSetting->addOwner($owner3);

        $this->assertCount(3, $this->userSiteSetting->getOwner());
        $this->assertTrue($this->userSiteSetting->getOwner()->contains($owner1));
        $this->assertTrue($this->userSiteSetting->getOwner()->contains($owner2));
        $this->assertTrue($this->userSiteSetting->getOwner()->contains($owner3));

        $this->userSiteSetting->removeOwner($owner2);
        $this->assertCount(2, $this->userSiteSetting->getOwner());
        $this->assertFalse($this->userSiteSetting->getOwner()->contains($owner2));
    }
}
