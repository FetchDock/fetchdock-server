<?php

namespace App\Tests\Integration\Repository;

use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Repository\DownloadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DownloadJobRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DownloadJobRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(DownloadJobRepository::class);
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testFindReusableByUriReturnsFinishedJobsForUriNewestFirst(): void
    {
        $uri = $this->createUniqueUri();

        $completed = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        $alreadyExists = $this->createJob($uri, DownloadStateEnum::ALREADY_EXISTS, withFile: true);
        $this->createJob($uri, DownloadStateEnum::PENDING, withFile: true);
        $this->createJob($uri, DownloadStateEnum::FAILED, withFile: true);
        $this->createJob($uri.'/other', DownloadStateEnum::COMPLETED, withFile: true);
        $this->entityManager->flush();

        $result = $this->repository->findReusableByUri($uri);

        $this->assertSame([$alreadyExists, $completed], $result);
    }

    public function testFindReusableByUriSkipsNewerSuccessfulJobWithoutFiles(): void
    {
        $uri = $this->createUniqueUri();

        // 1. Successful job with a downloaded file
        $jobWithFiles = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        // 2. Newer successful job for the same URI without files (e.g. a repeat download before files were reused)
        $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: false);
        // 3. Failed job for the same URI
        $this->createJob($uri, DownloadStateEnum::FAILED, withFile: false);
        $this->entityManager->flush();

        $result = $this->repository->findReusableByUri($uri);

        $this->assertNotEmpty($result);
        $this->assertSame($jobWithFiles, $result[0]);
        $this->assertSame([$jobWithFiles], $result);
    }

    public function testFindReusableByUriIncludesSoftDeletedJobsAndFiles(): void
    {
        $uri = $this->createUniqueUri();
        $job = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        $this->entityManager->flush();
        $jobId = $job->getId();
        $fileId = $job->getFiles()->first()->getId();

        // Soft-delete both through the SoftDeleteable listener, then reload from the database
        $this->entityManager->remove($job->getFiles()->first());
        $this->entityManager->remove($job);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $result = $this->repository->findReusableByUri($uri);

        $this->assertCount(1, $result);
        $this->assertSame($jobId, $result[0]->getId());
        $this->assertTrue($result[0]->isDeleted());
        $this->assertCount(1, $result[0]->getFiles());
        $this->assertSame($fileId, $result[0]->getFiles()->first()->getId());
        $this->assertTrue($result[0]->getFiles()->first()->isDeleted());

        // The soft-delete filter is enabled again afterwards
        $this->assertTrue($this->entityManager->getFilters()->isEnabled('softdeleteable'));
        $this->entityManager->clear();
        $this->assertNull($this->repository->find($jobId));
    }

    public function testFindReusableByUriReturnsEmptyArrayForUnknownUri(): void
    {
        $this->assertSame([], $this->repository->findReusableByUri('https://example.com/never-downloaded'));
    }

    private function createUniqueUri(): string
    {
        return 'https://example.com/reusable-'.bin2hex(random_bytes(4));
    }

    private function createJob(string $uri, DownloadStateEnum $state, bool $withFile): DownloadJob
    {
        $job = new DownloadJob()
            ->setUri($uri)
            ->setDownloader('mock')
            ->setState($state);
        $this->entityManager->persist($job);

        if ($withFile) {
            $file = new DownloadedFile()
                ->setPath('/downloads/test/'.bin2hex(random_bytes(4)).'.mp4')
                ->setVisible(true);
            $job->addFile($file);
            $this->entityManager->persist($file);
        }

        return $job;
    }
}
