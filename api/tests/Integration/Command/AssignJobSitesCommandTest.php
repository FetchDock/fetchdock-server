<?php

namespace App\Tests\Integration\Command;

use App\Entity\DownloadJob;
use App\Entity\Site;
use App\Enum\DownloadStateEnum;
use App\Tests\Api\SiteTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AssignJobSitesCommandTest extends KernelTestCase
{
    use SiteTestTrait;

    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->syncSites();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->commandTester = new CommandTester((new Application(self::$kernel))->find('app:jobs:assign-sites'));
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testFillsMissingSitesOnly(): void
    {
        $vimeo = $this->entityManager->getRepository(Site::class)->findOneBy(['slug' => 'vimeo']);
        $other = $this->entityManager->getRepository(Site::class)->findOneBy(['slug' => 'youtube']);
        $missing = $this->createJob('https://vimeo.com/'.random_int(100000000, 999999999));
        $deleted = $this->createJob('https://player.vimeo.com/'.random_int(100000000, 999999999))->setDeletedAt(new \DateTime());
        $assigned = $this->createJob('https://vimeo.com/'.random_int(100000000, 999999999))->setSite($other);
        $unknown = $this->createJob('https://unknown-'.bin2hex(random_bytes(4)).'.invalid/video');
        $this->entityManager->flush();

        $this->assertSame(Command::SUCCESS, $this->commandTester->execute(['--known-domains' => true]));

        $this->assertSame($vimeo->getId(), $this->siteId($missing));
        $this->assertSame($vimeo->getId(), $this->siteId($deleted), 'Soft-deleted jobs too');
        $this->assertSame($other->getId(), $this->siteId($assigned), 'Assigned sites are kept');
        $this->assertNull($this->siteId($unknown));
    }

    private function createJob(string $uri): DownloadJob
    {
        $job = new DownloadJob()->setUri($uri)->setDownloader('mock')->setState(DownloadStateEnum::COMPLETED);
        $this->entityManager->persist($job);

        return $job;
    }

    private function siteId(DownloadJob $job): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT site_id FROM download_job WHERE id = ?', [$job->getId()]);

        return false === $id || null === $id ? null : (int) $id;
    }
}
