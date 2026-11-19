<?php

namespace App\Tests\Integration\Repository;

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
        $uri = 'https://example.com/reusable-'.bin2hex(random_bytes(4));

        $completed = $this->createJob($uri, DownloadStateEnum::COMPLETED);
        $alreadyExists = $this->createJob($uri, DownloadStateEnum::ALREADY_EXISTS);
        $this->createJob($uri, DownloadStateEnum::PENDING);
        $this->createJob($uri, DownloadStateEnum::FAILED);
        $this->createJob($uri.'/other', DownloadStateEnum::COMPLETED);
        $this->entityManager->flush();

        $result = $this->repository->findReusableByUri($uri);

        $this->assertSame([$alreadyExists, $completed], $result);
    }

    public function testFindReusableByUriReturnsEmptyArrayForUnknownUri(): void
    {
        $this->assertSame([], $this->repository->findReusableByUri('https://example.com/never-downloaded'));
    }

    private function createJob(string $uri, DownloadStateEnum $state): DownloadJob
    {
        $job = new DownloadJob()
            ->setUri($uri)
            ->setDownloader('mock')
            ->setState($state);
        $this->entityManager->persist($job);

        return $job;
    }
}
