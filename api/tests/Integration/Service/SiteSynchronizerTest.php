<?php

namespace App\Tests\Integration\Service;

use App\Entity\Site;
use App\Entity\SiteExtractor;
use App\Repository\SiteExtractorRepository;
use App\Repository\SiteRepository;
use App\Service\Site\SiteSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SiteSynchronizerTest extends KernelTestCase
{
    public function testCuratedSiteTakesOverExtractorsOfAnAutomaticSiteAndRemovesIt(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $synchronizer = $container->get(SiteSynchronizer::class);
        $synchronizer->sync();

        // Simulate an automatic site that grabbed a twitter extractor before it was curated
        $extractor = $container->get(SiteExtractorRepository::class)->findOneByDownloaderAndKey('yt-dlp-cli', 'TwitterSpaces');
        $automatic = new Site()->setSlug('auto-'.bin2hex(random_bytes(4)))->setName('Automatic');
        $automatic->addExtractor($extractor);
        $entityManager->persist($automatic);
        $entityManager->flush();
        $slug = $automatic->getSlug();
        $entityManager->clear();

        $result = $synchronizer->sync();
        $entityManager->clear();

        $this->assertContains($slug, $result['removedSites']);
        $this->assertNull($container->get(SiteRepository::class)->findOneBySlug($slug));
        $moved = $container->get(SiteExtractorRepository::class)->findOneByDownloaderAndKey('yt-dlp-cli', 'TwitterSpaces');
        $this->assertInstanceOf(SiteExtractor::class, $moved);
        $this->assertSame('twitter', $moved->getSite()->getSlug());
    }
}
