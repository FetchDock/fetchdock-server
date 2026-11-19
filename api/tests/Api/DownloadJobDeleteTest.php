<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deleting a download job soft-deletes it, and soft-deletes its files once no live job uses them (#47).
 */
class DownloadJobDeleteTest extends ApiTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testOwnerDeletesJobAndItsUnsharedFiles(): void
    {
        $client = static::createClient();
        [$jobUuid, $fileId] = $this->createJobWithFile('user');

        $client->request('DELETE', '/download_jobs/'.$jobUuid, ['headers' => $this->authHeaders('user', 'userpass')]);
        $this->assertResponseStatusCodeSame(204);

        // Hidden from the API afterwards
        $client->request('GET', '/download_jobs/'.$jobUuid, ['headers' => $this->authHeaders('user', 'userpass')]);
        $this->assertResponseStatusCodeSame(404);

        // Kept in the database, soft-deleted, with the link between job and file intact
        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($jobUuid, $fileId) {
            $job = $entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $jobUuid]);
            $file = $entityManager->find(DownloadedFile::class, $fileId);

            $this->assertNotNull($job);
            $this->assertTrue($job->isDeleted());
            $this->assertNotNull($file);
            $this->assertTrue($file->isDeleted());
            $this->assertTrue($file->getDownloadJob()->contains($job));
        });
    }

    public function testFileUsedByAnotherLiveJobIsNotDeleted(): void
    {
        $client = static::createClient();
        [$userJobUuid, $fileId] = $this->createJobWithFile('user');
        $this->linkFileToNewJob($fileId, 'admin');

        $client->request('DELETE', '/download_jobs/'.$userJobUuid, ['headers' => $this->authHeaders('user', 'userpass')]);
        $this->assertResponseStatusCodeSame(204);

        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($userJobUuid, $fileId) {
            $this->assertTrue($entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $userJobUuid])->isDeleted());
            $this->assertFalse($entityManager->find(DownloadedFile::class, $fileId)->isDeleted());
        });
    }

    public function testUserCannotDeleteAnotherUsersJob(): void
    {
        $client = static::createClient();
        [$adminJobUuid] = $this->createJobWithFile('admin');

        $client->request('DELETE', '/download_jobs/'.$adminJobUuid, ['headers' => $this->authHeaders('user', 'userpass')]);
        $this->assertResponseStatusCodeSame(404);

        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($adminJobUuid) {
            $this->assertFalse($entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $adminJobUuid])->isDeleted());
        });
    }

    public function testAnonymousUserCannotDeleteJob(): void
    {
        $client = static::createClient();
        [$jobUuid] = $this->createJobWithFile('user');

        $client->request('DELETE', '/download_jobs/'.$jobUuid);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testResubmittingUriRestoresSoftDeletedFileStillOnDisk(): void
    {
        $client = static::createClient();
        [$jobUuid, $fileId, $uri] = $this->createJobWithFile('user');

        $client->request('DELETE', '/download_jobs/'.$jobUuid, ['headers' => $this->authHeaders('user', 'userpass')]);
        $this->assertResponseStatusCodeSame(204);

        $response = $client->request('POST', '/download_jobs', [
            'json' => ['uri' => $uri],
            'headers' => ['Content-Type' => 'application/ld+json'] + $this->authHeaders('admin', 'adminpass'),
        ]);
        $this->assertResponseStatusCodeSame(202);
        $newJobUuid = $response->toArray()['jobUuid'];

        $entityManager = $this->entityManager();
        $newJob = $entityManager->getRepository(DownloadJob::class)->findOneBy(['uuid' => $newJobUuid]);
        $file = $entityManager->find(DownloadedFile::class, $fileId);

        $this->assertSame(DownloadStateEnum::ALREADY_EXISTS, $newJob->getState());
        $this->assertNotNull($file, 'The restored file is visible again with the soft-delete filter enabled');
        $this->assertFalse($file->isDeleted());
        $this->assertTrue($file->getDownloadJob()->contains($newJob));
    }

    /**
     * @return array{string, int, string} the job UUID, the file ID and the job URI
     */
    private function createJobWithFile(string $ownerSubject): array
    {
        $entityManager = $this->entityManager();
        $uri = 'https://example.com/delete-test-'.bin2hex(random_bytes(6));
        $path = tempnam(sys_get_temp_dir(), 'delete-test-');
        $this->temporaryFiles[] = $path;

        $job = new DownloadJob()
            ->setUri($uri)
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::COMPLETED)
            ->setOwner($this->owner($entityManager, $ownerSubject));
        $file = new DownloadedFile()->setPath($path)->setVisible(true);
        $job->addFile($file);

        $entityManager->persist($job);
        $entityManager->persist($file);
        $entityManager->flush();

        return [$job->getUuid()->toRfc4122(), $file->getId(), $uri];
    }

    private function linkFileToNewJob(int $fileId, string $ownerSubject): void
    {
        $entityManager = $this->entityManager();
        $file = $entityManager->find(DownloadedFile::class, $fileId);

        $job = new DownloadJob()
            ->setUri('https://example.com/delete-test-other-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::ALREADY_EXISTS)
            ->setOwner($this->owner($entityManager, $ownerSubject));
        $job->addFile($file);

        $entityManager->persist($job);
        $entityManager->flush();
    }

    private function owner(EntityManagerInterface $entityManager, string $subject): OidcSubjectIdentifier
    {
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $subject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$subject}', run `make prep-test`");

        return $owner;
    }

    /**
     * @param callable(EntityManagerInterface): void $assertions
     */
    private function withoutSoftDeleteFilter(callable $assertions): void
    {
        $entityManager = $this->entityManager();
        $entityManager->getFilters()->disable('softdeleteable');
        try {
            $assertions($entityManager);
        } finally {
            $entityManager->getFilters()->enable('softdeleteable');
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }

    /**
     * @return array{Authorization: string}
     */
    private function authHeaders(string $username, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode($username.':'.$password)];
    }
}
