<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use App\Tests\Double\DownloadLocations;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The /admin/... operations of #55: all users' download jobs, files and events, including soft-deleted ones.
 */
class AdminDownloadsTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    /** A unique URI prefix per test, used with the "uri" filter to ignore other tests' jobs */
    private string $uriPrefix;
    /** @var list<int> */
    private array $jobIds = [];
    /** @var list<int> */
    private array $fileIds = [];
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->uriPrefix = 'https://example.com/admin-test-'.bin2hex(random_bytes(6)).'/';
    }

    protected function tearDown(): void
    {
        // Jobs and files are soft-deleteable without hard deletes, so remove()s never delete the rows
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $types = ['ids' => ArrayParameterType::INTEGER];
        $connection->executeStatement('DELETE FROM downloaded_file_download_job WHERE downloaded_file_id IN (:ids)', ['ids' => $this->fileIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM downloaded_file WHERE id IN (:ids)', ['ids' => $this->fileIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM download_job_event WHERE download_job_id IN (:ids)', ['ids' => $this->jobIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM download_job WHERE id IN (:ids)', ['ids' => $this->jobIds ?: [0]], $types);
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public static function viewOperations(): iterable
    {
        yield 'jobs' => ['/admin/download_jobs'];
        yield 'files' => ['/admin/downloaded_files'];
    }

    #[DataProvider('viewOperations')]
    public function testUsersCannotViewAllDownloads(string $path): void
    {
        $this->request('GET', $path, 'user');
        $this->assertResponseStatusCodeSame(403);

        $this->request('GET', $path, 'viewer');
        $this->assertResponseIsSuccessful();

        $this->request('GET', $path, 'admin');
        $this->assertResponseIsSuccessful();
    }

    public function testViewersCannotChangeDownloads(): void
    {
        $job = $this->createJob('user', deleted: true);
        $file = $this->createFile($job, deleted: true);

        $this->request('POST', '/admin/download_jobs/'.$this->uuid($job).'/restore', 'viewer');
        $this->assertResponseStatusCodeSame(403);
        $this->request('POST', '/admin/downloaded_files/'.$file->getId().'/restore', 'viewer');
        $this->assertResponseStatusCodeSame(403);
        $this->request('DELETE', '/admin/download_jobs/'.$this->uuid($job), 'viewer');
        $this->assertResponseStatusCodeSame(403);
        $this->request('DELETE', '/admin/download_jobs/'.$this->uuid($job), 'user');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testListsAllUsersJobsIncludingSoftDeletedOnes(): void
    {
        $userJob = $this->createJob('user');
        $deletedJob = $this->createJob('admin', deleted: true, state: DownloadStateEnum::FAILED);

        $jobs = $this->members('/admin/download_jobs?uri='.urlencode($this->uriPrefix));

        $this->assertEqualsCanonicalizing([$this->uuid($userJob), $this->uuid($deletedJob)], array_column($jobs, 'uuid'));
        $deleted = $this->byUuid($jobs, $deletedJob);
        $this->assertNotNull($deleted['deletedAt']);
        $this->assertSame('admin', $deleted['owner']['subject']);
        $this->assertSame($deletedJob->getOwner()->getUuid()->toRfc4122(), $deleted['owner']['uuid']);
        $this->assertNull($this->byUuid($jobs, $userJob)['deletedAt']);
        $this->assertArrayNotHasKey('token', $deleted);
        $this->assertArrayNotHasKey('cookies', $deleted);
    }

    public function testFiltersJobs(): void
    {
        $userJob = $this->createJob('user');
        $deletedJob = $this->createJob('admin', deleted: true, state: DownloadStateEnum::FAILED);
        $uri = urlencode($this->uriPrefix);
        $userUuid = $userJob->getOwner()->getUuid()->toRfc4122();

        $this->assertSame([$this->uuid($deletedJob)], array_column($this->members("/admin/download_jobs?uri=$uri&deleted=true"), 'uuid'));
        $this->assertSame([$this->uuid($userJob)], array_column($this->members("/admin/download_jobs?uri=$uri&deleted=false"), 'uuid'));
        $this->assertSame([$this->uuid($userJob)], array_column($this->members("/admin/download_jobs?uri=$uri&owner=$userUuid"), 'uuid'));
        $this->assertSame([$this->uuid($deletedJob)], array_column($this->members("/admin/download_jobs?uri=$uri&state=3"), 'uuid'));

        $this->request('GET', '/admin/download_jobs?owner=not-a-uuid', 'admin');
        $this->assertResponseStatusCodeSame(422);
    }

    public function testShowsASoftDeletedJobWithItsFilesAndEvents(): void
    {
        $job = $this->createJob('user', deleted: true);
        $liveFile = $this->createFile($job);
        $deletedFile = $this->createFile($job, deleted: true, visible: false);
        $this->createEvent($job, 'job.completed');

        $data = $this->request('GET', '/admin/download_jobs/'.$this->uuid($job), 'viewer')->toArray();
        $this->assertSame(2, $data['fileCount']);
        $this->assertNotNull($data['deletedAt']);

        $files = $this->members('/admin/download_jobs/'.$this->uuid($job).'/files', 'viewer');
        $this->assertSame([$liveFile->getId(), $deletedFile->getId()], array_column($files, 'id'));
        $this->assertSame($deletedFile->getPath(), $files[1]['path']);
        $this->assertTrue($files[1]['existsOnDisk']);
        $this->assertFalse($files[1]['visible']);
        $this->assertNotNull($files[1]['deletedAt']);
        $this->assertSame([$this->uuid($job)], array_column($files[1]['jobs'], 'uuid'));
        // The only linked job is deleted, so the file can't be downloaded through it
        $this->assertNull($files[1]['downloadUri']);

        $events = $this->members('/admin/download_jobs/'.$this->uuid($job).'/events', 'viewer');
        $this->assertSame(['job.completed'], array_column($events, 'event'));
    }

    public function testUnknownJobsAndFilesAreNotFound(): void
    {
        foreach (['/admin/download_jobs/00000000-0000-4000-8000-000000000000', '/admin/download_jobs/not-a-uuid', '/admin/download_jobs/not-a-uuid/files', '/admin/download_jobs/not-a-uuid/events', '/admin/downloaded_files/999999999'] as $path) {
            $this->request('GET', $path, 'admin');
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testFiltersFilesByDeletedAndOrphaned(): void
    {
        $liveJob = $this->createJob('user');
        $deletedJob = $this->createJob('admin', deleted: true);
        $usedFile = $this->createFile($liveJob);
        $orphanedFile = $this->createFile($deletedJob, deleted: true);

        $deletedFiles = array_column($this->members('/admin/downloaded_files?deleted=true&itemsPerPage=1000'), 'id');
        $this->assertContains($orphanedFile->getId(), $deletedFiles);
        $this->assertNotContains($usedFile->getId(), $deletedFiles);

        $orphanedFiles = array_column($this->members('/admin/downloaded_files?orphaned=true&itemsPerPage=1000'), 'id');
        $this->assertContains($orphanedFile->getId(), $orphanedFiles);
        $this->assertNotContains($usedFile->getId(), $orphanedFiles);

        $usedFiles = array_column($this->members('/admin/downloaded_files?orphaned=false&itemsPerPage=1000'), 'id');
        $this->assertContains($usedFile->getId(), $usedFiles);
        $this->assertNotContains($orphanedFile->getId(), $usedFiles);

        $file = $this->request('GET', '/admin/downloaded_files/'.$orphanedFile->getId(), 'viewer')->toArray();
        $this->assertSame('admin', $file['jobs'][0]['owner']['subject']);
    }

    public function testRestoresAJobAndItsFilesThatStillExist(): void
    {
        $job = $this->createJob('user', deleted: true);
        $existingFile = $this->createFile($job, deleted: true);
        $missingFile = $this->createFile($job, deleted: true, onDisk: false);

        $data = $this->request('POST', '/admin/download_jobs/'.$this->uuid($job).'/restore', 'admin')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertNull($data['deletedAt']);
        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($job, $existingFile, $missingFile): void {
            $this->assertFalse($entityManager->find(DownloadJob::class, $job->getId())->isDeleted());
            $this->assertFalse($entityManager->find(DownloadedFile::class, $existingFile->getId())->isDeleted());
            $this->assertTrue($entityManager->find(DownloadedFile::class, $missingFile->getId())->isDeleted());
        });

        // The owner sees the job again
        $this->request('GET', '/download_jobs/'.$this->uuid($job), 'user');
        $this->assertResponseIsSuccessful();

        $this->request('POST', '/admin/download_jobs/'.$this->uuid($job).'/restore', 'admin');
        $this->assertResponseStatusCodeSame(409);
    }

    public function testRestoresAFileThatStillExists(): void
    {
        $job = $this->createJob('user', deleted: true);
        $existingFile = $this->createFile($job, deleted: true);
        $missingFile = $this->createFile($job, deleted: true, onDisk: false);
        $liveFile = $this->createFile($this->createJob('user'));

        $data = $this->request('POST', '/admin/downloaded_files/'.$existingFile->getId().'/restore', 'admin')->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertNull($data['deletedAt']);

        $this->request('POST', '/admin/downloaded_files/'.$missingFile->getId().'/restore', 'admin');
        $this->assertResponseStatusCodeSame(409);
        $this->request('POST', '/admin/downloaded_files/'.$liveFile->getId().'/restore', 'admin');
        $this->assertResponseStatusCodeSame(409);
    }

    public function testPermanentlyDeletesASoftDeletedJobAndKeepsItsFiles(): void
    {
        $job = $this->createJob('user', deleted: true);
        $ownFile = $this->createFile($job);
        $sharedFile = $this->createFile($job);
        $otherJob = $this->createJob('admin');
        $this->linkFile($sharedFile, $otherJob);
        $this->createEvent($job, 'job.completed');

        $this->request('DELETE', '/admin/download_jobs/'.$this->uuid($job), 'admin');

        $this->assertResponseStatusCodeSame(204);
        $this->request('GET', '/admin/download_jobs/'.$this->uuid($job), 'admin');
        $this->assertResponseStatusCodeSame(404);
        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($job, $ownFile, $sharedFile): void {
            $this->assertNull($entityManager->find(DownloadJob::class, $job->getId()));
            $this->assertSame(0, $entityManager->getRepository(DownloadJobEvent::class)->count(['downloadJob' => $job->getId()]));

            $own = $entityManager->find(DownloadedFile::class, $ownFile->getId());
            $this->assertTrue($own->isDeleted(), 'a file no live job uses is soft-deleted');
            $this->assertCount(0, $own->getDownloadJob());
            $this->assertFileExists($own->getPath());

            $shared = $entityManager->find(DownloadedFile::class, $sharedFile->getId());
            $this->assertFalse($shared->isDeleted(), 'a file another live job uses stays live');
            $this->assertCount(1, $shared->getDownloadJob());
        });
    }

    public function testLiveJobsCannotBePermanentlyDeleted(): void
    {
        $job = $this->createJob('user');

        $this->request('DELETE', '/admin/download_jobs/'.$this->uuid($job), 'admin');

        $this->assertResponseStatusCodeSame(409);
        $this->request('GET', '/download_jobs/'.$this->uuid($job), 'user');
        $this->assertResponseIsSuccessful();
    }

    private function request(string $method, string $path, string $user): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->client->request($method, $path, ['headers' => [
            'Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass'),
            'Accept' => 'application/ld+json',
        ]]);
    }

    private function members(string $path, string $user = 'admin'): array
    {
        $response = $this->request('GET', $path, $user);
        $this->assertResponseIsSuccessful();

        return $response->toArray()['member'];
    }

    private function byUuid(array $jobs, DownloadJob $job): array
    {
        return array_values(array_filter($jobs, fn (array $data): bool => $data['uuid'] === $this->uuid($job)))[0];
    }

    private function uuid(DownloadJob $job): string
    {
        return $job->getUuid()->toRfc4122();
    }

    private function createJob(string $ownerSubject, bool $deleted = false, DownloadStateEnum $state = DownloadStateEnum::COMPLETED): DownloadJob
    {
        $entityManager = $this->entityManager();
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $ownerSubject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$ownerSubject}', run `make prep-test`");

        $job = new DownloadJob()
            ->setUri($this->uriPrefix.bin2hex(random_bytes(4)))
            ->setDownloader('mock')
            ->setState($state)
            ->setOwner($owner);
        if ($deleted) {
            $job->setDeletedAt(new \DateTime());
        }
        $entityManager->persist($job);
        $entityManager->flush();
        $this->jobIds[] = $job->getId();

        return $job;
    }

    private function createFile(DownloadJob $job, bool $deleted = false, bool $visible = true, bool $onDisk = true): DownloadedFile
    {
        if ($onDisk) {
            $path = tempnam(sys_get_temp_dir(), 'admin-test-');
            $this->temporaryFiles[] = $path;
        } else {
            $path = sys_get_temp_dir().'/admin-test-missing-'.bin2hex(random_bytes(6));
        }

        return $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($job, $path, $deleted, $visible): DownloadedFile {
            $file = DownloadLocations::persistedDirectoryFile($entityManager, $path)->setVisible($visible);
            if ($deleted) {
                $file->setDeletedAt(new \DateTime());
            }
            $file->addDownloadJob($entityManager->find(DownloadJob::class, $job->getId()));
            $entityManager->persist($file);
            $entityManager->flush();
            $this->fileIds[] = $file->getId();

            return $file;
        });
    }

    private function linkFile(DownloadedFile $file, DownloadJob $job): void
    {
        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($file, $job): void {
            $entityManager->find(DownloadedFile::class, $file->getId())->addDownloadJob($entityManager->find(DownloadJob::class, $job->getId()));
            $entityManager->flush();
        });
    }

    private function createEvent(DownloadJob $job, string $event): void
    {
        $this->withoutSoftDeleteFilter(function (EntityManagerInterface $entityManager) use ($job, $event): void {
            $entityManager->persist(new DownloadJobEvent()
                ->setDownloadJob($entityManager->find(DownloadJob::class, $job->getId()))
                ->setEvent($event)
                ->setSource('test'));
            $entityManager->flush();
        });
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $callback
     *
     * @return T
     */
    private function withoutSoftDeleteFilter(callable $callback): mixed
    {
        $entityManager = $this->entityManager();
        $entityManager->getFilters()->disable('softdeleteable');
        try {
            return $callback($entityManager);
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
}
