<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Site;
use App\Entity\SiteExtractor;
use PHPUnit\Framework\TestCase;

class SiteTest extends TestCase
{
    public function testDefaults(): void
    {
        $site = new Site();

        $this->assertTrue($site->isEnabled());
        $this->assertFalse($site->isCurated());
        $this->assertSame([], $site->getDomains());
        $this->assertCount(0, $site->getExtractors());
    }

    public function testDomainsAreNormalisedUniqueAndSorted(): void
    {
        $site = new Site()->setDomains(['X.com', ' twitter.com ', 'x.com', '']);
        $site->addDomain('Mobile.Twitter.com');

        $this->assertSame(['mobile.twitter.com', 'twitter.com', 'x.com'], $site->getDomains());
        $this->assertTrue($site->hasDomain('TWITTER.COM'));
        $this->assertFalse($site->hasDomain('example.com'));
    }

    public function testAddExtractorMovesItFromItsPreviousSite(): void
    {
        $automatic = new Site()->setSlug('twitter-auto');
        $curated = new Site()->setSlug('twitter');
        $extractor = new SiteExtractor('gallery-dl-cli', 'twitter');

        $automatic->addExtractor($extractor);
        $curated->addExtractor($extractor);

        $this->assertSame($curated, $extractor->getSite());
        $this->assertCount(0, $automatic->getExtractors());
        $this->assertCount(1, $curated->getExtractors());
        $this->assertSame('gallery-dl-cli', $extractor->getDownloader());
        $this->assertSame('twitter', $extractor->getExtractorKey());
    }
}
