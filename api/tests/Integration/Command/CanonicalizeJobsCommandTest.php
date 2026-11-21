<?php

namespace App\Tests\Integration\Command;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * bin/resolve_url.py is replaced by App\Tests\Double\FakeExtractorMatcher.
 */
class CanonicalizeJobsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->commandTester = new CommandTester((new Application(self::$kernel))->find('app:jobs:canonicalize'));
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testFillsMissingKeysAndReportsDuplicatesWithoutMergingThem(): void
    {
        $videoId = (string) random_int(100000000, 999999999);
        $owner = new OidcSubjectIdentifier()->setSubject('canonicalize-'.bin2hex(random_bytes(4)));
        $this->entityManager->persist($owner);
        $first = $this->createJob('https://vimeo.com/'.$videoId, $owner);
        $second = $this->createJob('https://fr.vimeo.com/'.$videoId.'?share=copy', $owner);
        $keyed = $this->createJob('https://example.com/keyed', $owner)->setCanonicalKey('url:custom');
        $this->entityManager->flush();

        $this->assertSame(Command::SUCCESS, $this->commandTester->execute(['--batch' => 1]));

        $this->assertSame('ytdlp:Vimeo:'.$videoId, $this->canonicalKey($first));
        $this->assertSame('ytdlp:Vimeo:'.$videoId, $this->canonicalKey($second));
        $this->assertSame('url:custom', $this->canonicalKey($keyed));
        $this->assertStringContainsString('share a canonical key', $this->commandTester->getDisplay());
        $this->assertNotNull($this->entityManager->find(DownloadJob::class, $second->getId()));

        // Idempotent: nothing left to fill; --force recomputes all keys
        $this->commandTester->execute([]);
        $this->assertStringContainsString('Canonicalized 0 download jobs', $this->commandTester->getDisplay());
        $this->commandTester->execute(['--force' => true]);
        $this->assertSame('url:https://example.com/keyed', $this->canonicalKey($keyed));
    }

    public function testDryRunDoesNotWrite(): void
    {
        $job = $this->createJob('https://vimeo.com/'.random_int(100000000, 999999999), null);
        $this->entityManager->flush();

        $this->commandTester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('Computed canonical keys of', $this->commandTester->getDisplay());
        $this->assertNull($this->canonicalKey($job));
    }

    private function createJob(string $uri, ?OidcSubjectIdentifier $owner): DownloadJob
    {
        $job = new DownloadJob()->setUri($uri)->setDownloader('mock')->setState(DownloadStateEnum::COMPLETED)->setOwner($owner);
        $this->entityManager->persist($job);

        return $job;
    }

    private function canonicalKey(DownloadJob $job): ?string
    {
        $key = $this->entityManager->getConnection()->fetchOne('SELECT canonical_key FROM download_job WHERE id = ?', [$job->getId()]);

        return false === $key ? null : $key;
    }
}
