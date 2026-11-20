<?php

namespace App\Tests\Integration\Command;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DownloadRouterMigrationSetDownloadjobOwnerCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->commandTester = new CommandTester(
            (new Application(self::$kernel))->find('download-router:migration:set-downloadjob-owner')
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testAssignsAllJobsWithoutOwnerAndKeepsExistingOwners(): void
    {
        $user = $this->owner('user');
        $admin = $this->owner('admin');
        $orphans = [$this->createJob(null), $this->createJob(null), $this->createJob(null)];
        $adminsJob = $this->createJob($admin);
        $this->entityManager->flush();

        $this->commandTester->execute(['owner-sub' => 'user']);

        $this->commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Set owner user on', $this->commandTester->getDisplay());
        $this->entityManager->clear();
        foreach ($orphans as $orphan) {
            $this->assertSame($user->getId(), $this->reload($orphan)->getOwner()?->getId());
        }
        $this->assertSame($admin->getId(), $this->reload($adminsJob)->getOwner()?->getId());
        $this->assertSame(0, $this->countJobsWithoutOwner());
    }

    public function testDryRunOnlyReportsTheCount(): void
    {
        $this->createJob(null);
        $this->createJob(null);
        $this->entityManager->flush();
        $expected = $this->countJobsWithoutOwner();

        $this->commandTester->execute(['owner-sub' => 'user', '--dry-run' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString(sprintf('Would set owner user on %d download job(s)', $expected), $this->commandTester->getDisplay());
        $this->assertSame($expected, $this->countJobsWithoutOwner());
    }

    public function testFailsForUnknownOwner(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Owner sub not found');

        $this->commandTester->execute(['owner-sub' => 'unknown-subject']);
    }

    private function createJob(?OidcSubjectIdentifier $owner): DownloadJob
    {
        $job = new DownloadJob()
            ->setUri('https://example.com/owner-migration-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::COMPLETED)
            ->setOwner($owner);
        $this->entityManager->persist($job);

        return $job;
    }

    private function reload(DownloadJob $job): DownloadJob
    {
        return $this->entityManager->find(DownloadJob::class, $job->getId());
    }

    private function owner(string $subject): OidcSubjectIdentifier
    {
        $owner = $this->entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $subject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$subject}', run `make prep-test`");

        return $owner;
    }

    private function countJobsWithoutOwner(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(DownloadJob::class, 'd')
            ->where('d.owner IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
