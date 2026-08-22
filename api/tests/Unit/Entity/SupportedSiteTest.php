<?php

namespace App\Tests\Unit\Entity;

use App\Entity\SiteSetting;
use App\Entity\SupportedSite;
use PHPUnit\Framework\TestCase;

class SupportedSiteTest extends TestCase
{
    private SupportedSite $supportedSite;

    protected function setUp(): void
    {
        $this->supportedSite = new SupportedSite();
    }

    public function testGetIdReturnsNull(): void
    {
        $this->assertNull($this->supportedSite->getId());
    }

    public function testSetAndGetName(): void
    {
        $name = 'YouTube';
        $result = $this->supportedSite->setName($name);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($name, $this->supportedSite->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $description = 'Video hosting platform';
        $result = $this->supportedSite->setDescription($description);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($description, $this->supportedSite->getDescription());
    }

    public function testSetAndGetDescriptionNull(): void
    {
        $result = $this->supportedSite->setDescription(null);

        $this->assertSame($this->supportedSite, $result);
        $this->assertNull($this->supportedSite->getDescription());
    }

    public function testSetAndGetDomains(): void
    {
        $domains = ['youtube.com', 'www.youtube.com', 'youtube.be'];
        $result = $this->supportedSite->setDomains($domains);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($domains, $this->supportedSite->getDomains());
    }

    public function testSetAndGetEnabled(): void
    {
        $result = $this->supportedSite->setEnabled(true);

        $this->assertSame($this->supportedSite, $result);
        $this->assertTrue($this->supportedSite->isEnabled());
    }

    public function testSetAndGetEnabledFalse(): void
    {
        $result = $this->supportedSite->setEnabled(false);

        $this->assertSame($this->supportedSite, $result);
        $this->assertFalse($this->supportedSite->isEnabled());
    }

    public function testSetAndGetMetadata(): void
    {
        $metadata = ['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->supportedSite->setMetadata($metadata);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($metadata, $this->supportedSite->getMetadata());
    }

    public function testSetAndGetDownloader(): void
    {
        $downloader = 'youtube-dl';
        $result = $this->supportedSite->setDownloader($downloader);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($downloader, $this->supportedSite->getDownloader());
    }

    public function testSetAndGetDownloaderSpecificIdentifier(): void
    {
        $identifier = 'youtube';
        $result = $this->supportedSite->setDownloaderSpecificIdentifier($identifier);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($identifier, $this->supportedSite->getDownloaderSpecificIdentifier());
    }

    public function testSetAndGetDownloaderSpecificSubIdentifier(): void
    {
        $subIdentifier = 'work';
        $result = $this->supportedSite->setDownloaderSpecificSubIdentifier($subIdentifier);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($subIdentifier, $this->supportedSite->getDownloaderSpecificSubIdentifier());
    }

    public function testSetAndGetDownloaderSpecificSubIdentifierNull(): void
    {
        $result = $this->supportedSite->setDownloaderSpecificSubIdentifier(null);

        $this->assertSame($this->supportedSite, $result);
        $this->assertNull($this->supportedSite->getDownloaderSpecificSubIdentifier());
    }

    public function testSetAndGetDownloaderMetadata(): void
    {
        $metadata = ['format' => 'best', 'output' => '%(title)s.%(ext)s'];
        $result = $this->supportedSite->setDownloaderMetadata($metadata);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($metadata, $this->supportedSite->getDownloaderMetadata());
    }

    public function testSetAndGetDownloaderMetadataNull(): void
    {
        $result = $this->supportedSite->setDownloaderMetadata(null);

        $this->assertSame($this->supportedSite, $result);
        $this->assertNull($this->supportedSite->getDownloaderMetadata());
    }

    public function testSetAndGetDefaultSiteSetting(): void
    {
        $siteSetting = new SiteSetting();
        $result = $this->supportedSite->setDefaultSiteSetting($siteSetting);

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame($siteSetting, $this->supportedSite->getDefaultSiteSetting());
    }

    public function testSetDefaultSiteSettingSetsOwningsideRelation(): void
    {
        $siteSetting = new SiteSetting();
        $this->supportedSite->setDefaultSiteSetting($siteSetting);

        // The relation should be bidirectional
        $this->assertSame($this->supportedSite, $siteSetting->getSite());
    }

    public function testChainableSetters(): void
    {
        $result = $this->supportedSite
            ->setName('Twitter')
            ->setDescription('Social media platform')
            ->setDomains(['twitter.com', 'x.com'])
            ->setEnabled(true)
            ->setDownloader('gallery-dl');

        $this->assertSame($this->supportedSite, $result);
        $this->assertSame('Twitter', $this->supportedSite->getName());
        $this->assertSame('Social media platform', $this->supportedSite->getDescription());
        $this->assertTrue($this->supportedSite->isEnabled());
    }
}
