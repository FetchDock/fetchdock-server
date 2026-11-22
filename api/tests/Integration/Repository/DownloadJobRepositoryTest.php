<?php

namespace App\Tests\Integration\Repository;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
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

    public function testFindReusableByCanonicalKeyReturnsFinishedJobsForUriNewestFirst(): void
    {
        $uri = $this->createUniqueUri();

        $completed = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        $alreadyExists = $this->createJob($uri, DownloadStateEnum::ALREADY_EXISTS, withFile: true);
        $this->createJob($uri, DownloadStateEnum::PENDING, withFile: true);
        $this->createJob($uri, DownloadStateEnum::FAILED, withFile: true);
        $this->createJob($uri.'/other', DownloadStateEnum::COMPLETED, withFile: true);
        $this->entityManager->flush();

        $result = $this->repository->findReusableByCanonicalKey('url:'.$uri, $uri);

        $this->assertSame([$alreadyExists, $completed], $result);
    }

    public function testFindReusableByCanonicalKeySkipsNewerSuccessfulJobWithoutFiles(): void
    {
        $uri = $this->createUniqueUri();

        // 1. Successful job with a downloaded file
        $jobWithFiles = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        // 2. Newer successful job for the same URI without files (e.g. a repeat download before files were reused)
        $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: false);
        // 3. Failed job for the same URI
        $this->createJob($uri, DownloadStateEnum::FAILED, withFile: false);
        $this->entityManager->flush();

        $result = $this->repository->findReusableByCanonicalKey('url:'.$uri, $uri);

        $this->assertNotEmpty($result);
        $this->assertSame($jobWithFiles, $result[0]);
        $this->assertSame([$jobWithFiles], $result);
    }

    public function testFindReusableByCanonicalKeyIncludesSoftDeletedJobsAndFiles(): void
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

        $result = $this->repository->findReusableByCanonicalKey('url:'.$uri, $uri);

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

    public function testFindReusableByCanonicalKeyReturnsEmptyArrayForUnknownUri(): void
    {
        $this->assertSame([], $this->repository->findReusableByCanonicalKey('url:https://example.com/never-downloaded', 'https://example.com/never-downloaded'));
    }

    public function testFindReusableByCanonicalKeyMatchesOtherSpellingsAndLegacyJobsOfTheUri(): void
    {
        $uri = $this->createUniqueUri();
        $key = 'ytdlp:Test:'.bin2hex(random_bytes(4));

        $otherSpelling = $this->createJob($uri.'?playlist_id=abc', DownloadStateEnum::COMPLETED, withFile: true)->setCanonicalKey($key);
        // Created before canonical keys: matched by its URI until app:jobs:canonicalize fills the key
        $legacy = $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true);
        // Same URI but keyed as other media (e.g. after a profile change): the key wins
        $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: true)->setCanonicalKey($key.'-other');
        $this->entityManager->flush();

        $this->assertSame([$legacy, $otherSpelling], $this->repository->findReusableByCanonicalKey($key, $uri));
    }

    public function testFindByCanonicalKeyAndOwnerOnlyReturnsTheOwnersJobs(): void
    {
        $uri = $this->createUniqueUri();
        $key = 'url:'.$uri;
        $owner = $this->createOwner();
        $otherOwner = $this->createOwner();

        $keyed = $this->createJob($uri.'?utm_source=feed', DownloadStateEnum::COMPLETED, withFile: false)->setCanonicalKey($key)->setOwner($owner);
        $legacy = $this->createJob($uri, DownloadStateEnum::PENDING, withFile: false)->setOwner($owner);
        $this->createJob($uri, DownloadStateEnum::COMPLETED, withFile: false)->setCanonicalKey($key)->setOwner($otherOwner);
        $this->createJob($uri.'/other', DownloadStateEnum::COMPLETED, withFile: false)->setOwner($owner);
        $this->entityManager->flush();

        $result = $this->repository->findByCanonicalKeyAndOwner($key, $uri, $owner);

        $this->assertEqualsCanonicalizing([$keyed->getId(), $legacy->getId()], array_map(static fn (DownloadJob $job): ?int => $job->getId(), $result));
    }

    public function testCanonicalizationBatchAndDuplicateGroups(): void
    {
        $owner = $this->createOwner();
        $key = 'url:'.$this->createUniqueUri();
        $missing = $this->createJob($this->createUniqueUri(), DownloadStateEnum::COMPLETED, withFile: false)->setOwner($owner);
        $this->createJob($this->createUniqueUri(), DownloadStateEnum::COMPLETED, withFile: false)->setOwner($owner)->setCanonicalKey($key);
        $this->createJob($this->createUniqueUri(), DownloadStateEnum::COMPLETED, withFile: false)->setOwner($owner)->setCanonicalKey($key);
        $this->entityManager->flush();

        $this->assertSame(
            [['id' => $missing->getId(), 'uri' => $missing->getUri()]],
            $this->repository->findCanonicalizationBatch($missing->getId() - 1, 100, false),
        );
        $this->assertCount(3, array_filter(
            $this->repository->findCanonicalizationBatch($missing->getId() - 1, 100, true),
            static fn (array $row): bool => $row['id'] >= $missing->getId(),
        ));

        $this->repository->updateCanonicalKeys([$missing->getId() => $key]);
        $this->assertContains(
            ['owner_id' => $owner->getId(), 'canonical_key' => $key, 'jobs' => 3],
            $this->repository->findDuplicateCanonicalKeyGroups(1000),
        );
        $this->assertGreaterThanOrEqual(1, $this->repository->countDuplicateCanonicalKeyGroups());
    }

    private function createOwner(): OidcSubjectIdentifier
    {
        $owner = new OidcSubjectIdentifier()->setSubject('canonical-'.bin2hex(random_bytes(4)));
        $this->entityManager->persist($owner);

        return $owner;
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
                ->setLocation($this->entityManager->getRepository(DownloadDirectory::class)->findOneBy(['isDefault' => true]), 'test/'.bin2hex(random_bytes(4)).'.mp4')
                ->setVisible(true);
            $job->addFile($file);
            $this->entityManager->persist($file);
        }

        return $job;
    }
}
