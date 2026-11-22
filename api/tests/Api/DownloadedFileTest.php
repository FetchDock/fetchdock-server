<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use App\Tests\Double\DownloadLocations;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

class DownloadedFileTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** @var list<int> */
    private array $jobIds = [];
    /** @var list<int> */
    private array $fileIds = [];

    protected function tearDown(): void
    {
        // Jobs and files are soft-deleteable without hard deletes, so remove()s never delete the rows
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $types = ['ids' => ArrayParameterType::INTEGER];
        $connection->executeStatement('DELETE FROM downloaded_file_download_job WHERE downloaded_file_id IN (:ids)', ['ids' => $this->fileIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM downloaded_file WHERE id IN (:ids)', ['ids' => $this->fileIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM download_job_event WHERE download_job_id IN (:ids)', ['ids' => $this->jobIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM download_job WHERE id IN (:ids)', ['ids' => $this->jobIds ?: [0]], $types);

        parent::tearDown();
    }

    public function testOwnerListsTheFilesOfTheirJob(): void
    {
        $client = static::createClient();
        [$job] = $this->createJobWithFile('user', '/downloads/files-test/video.mp4');

        $files = $this->getFiles($client, $job, 'user');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $files);
        $this->assertSame('video.mp4', $files[0]['filename']);
        $this->assertSame($this->expectedDownloadUri($job, $files[0]['id']), $files[0]['downloadUri']);
    }

    public function testOtherUsersCannotListTheFilesOfAJob(): void
    {
        $client = static::createClient();
        [$job] = $this->createJobWithFile('user', '/downloads/files-test/video.mp4');

        $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122().'/files', ['headers' => $this->auth('admin')]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testFilesNeverExposeServerPathsOrOtherJobs(): void
    {
        $client = static::createClient();
        [$job, $file] = $this->createJobWithFile('user', '/downloads/files-test/secret-dir/video.mp4');
        $otherJob = $this->linkFileToNewJob($file, 'admin');

        $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122().'/files', ['headers' => $this->auth('user')]);
        $filesResponse = $client->getResponse()->getContent();
        $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122(), ['headers' => $this->auth('user')]);
        $jobResponse = $client->getResponse()->getContent();
        $client->request('GET', '/download_jobs', ['headers' => $this->auth('user')]);
        $collectionResponse = $client->getResponse()->getContent();

        foreach (['files' => $filesResponse, 'job' => $jobResponse, 'job collection' => $collectionResponse] as $name => $response) {
            $this->assertStringNotContainsString('secret-dir', $response, $name.' response exposes the server path');
            $this->assertStringNotContainsString($otherJob->getUuid()->toRfc4122(), $response, $name.' response exposes another user\'s job');
            $this->assertStringNotContainsString($otherJob->getToken(), $response, $name.' response exposes another user\'s token');
        }
    }

    public function testOnlyDescriptiveMetadataIsExposed(): void
    {
        $client = static::createClient();
        [$job] = $this->createJobWithFile('user', '/downloads/files-test/video.mp4', [
            'title' => 'A video',
            'ext' => 'mp4',
            'duration' => 12,
            '_filename' => '/downloads/files-test/video.mp4',
            'filepath' => '/downloads/files-test/video.mp4',
            'http_headers' => ['Cookie' => 'session=secret-cookie'],
            'formats' => [['cookies' => 'session=secret-cookie']],
        ]);

        $files = $this->getFiles($client, $job, 'user');

        $this->assertSame(['title' => 'A video', 'ext' => 'mp4', 'duration' => 12], $files[0]['metadata']);
        $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122(), ['headers' => $this->auth('user')]);
        $this->assertStringNotContainsString('secret-cookie', $client->getResponse()->getContent());
        $this->assertSame(['title' => 'A video', 'ext' => 'mp4', 'duration' => 12], $client->getResponse()->toArray()['files'][0]['metadata']);
    }

    public function testSharedFileUsesTheTokenOfTheRequestedJob(): void
    {
        $client = static::createClient();
        [$firstJob, $file] = $this->createJobWithFile('admin', '/downloads/files-test/shared.mp4');
        $secondJob = $this->linkFileToNewJob($file, 'user');

        $files = $this->getFiles($client, $secondJob, 'user');

        $this->assertCount(1, $files);
        $this->assertSame($this->expectedDownloadUri($secondJob, $file->getId()), $files[0]['downloadUri']);
        $this->assertStringNotContainsString($firstJob->getToken(), $files[0]['downloadUri']);
    }

    /**
     * A yt-dlp sidecar shares the media's .info.json metadata, its 'ext' included.
     */
    public function testYtDlpFileIsServedWithItsOwnExtension(): void
    {
        $client = static::createClient();
        $directory = sys_get_temp_dir().'/files-test-'.bin2hex(random_bytes(6));
        mkdir($directory);
        touch($directory.'/video.mp4');
        touch($directory.'/video.desktop');
        $metadata = ['title' => 'A video', 'ext' => 'mp4'];

        try {
            [$job, $media] = $this->createJobWithFile('user', $directory.'/video.mp4', $metadata, 'yt-dlp-cli');
            [, $shortcut] = $this->createJobWithFile('user', $directory.'/video.desktop', $metadata, 'yt-dlp-cli');
            $this->linkFileToJob($shortcut, $job);

            $client->request('GET', $this->expectedDownloadUri($job, $media->getId()));
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('filename="A video.mp4"', $client->getResponse()->getHeaders()['content-disposition'][0]);

            $client->request('GET', $this->expectedDownloadUri($job, $shortcut->getId()));
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('filename="A video.desktop"', $client->getResponse()->getHeaders()['content-disposition'][0]);
        } finally {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
    }

    private function getFiles($client, DownloadJob $job, string $user): array
    {
        return $client->request('GET', '/download_jobs/'.$job->getUuid()->toRfc4122().'/files', ['headers' => $this->auth($user)])->toArray()['member'];
    }

    private function expectedDownloadUri(DownloadJob $job, int $fileId): string
    {
        return '/download/'.$job->getUuid()->toRfc4122().'/'.$job->getToken().'/files/'.$fileId;
    }

    /**
     * @return array{DownloadJob, DownloadedFile}
     */
    private function createJobWithFile(string $ownerSubject, string $path, array $metadata = [], string $downloader = 'mock'): array
    {
        $entityManager = $this->entityManager();
        $job = $this->newJob($entityManager, $ownerSubject, DownloadStateEnum::COMPLETED)->setDownloader($downloader);
        $file = DownloadLocations::persistedDirectoryFile($entityManager, $path)->setVisible(true)->setMetadata($metadata);
        $job->addFile($file);

        $entityManager->persist($job);
        $entityManager->persist($file);
        $entityManager->flush();
        $this->jobIds[] = $job->getId();
        $this->fileIds[] = $file->getId();

        return [$job, $file];
    }

    private function linkFileToJob(DownloadedFile $file, DownloadJob $job): void
    {
        $entityManager = $this->entityManager();
        $entityManager->find(DownloadJob::class, $job->getId())->addFile($entityManager->find(DownloadedFile::class, $file->getId()));
        $entityManager->flush();
    }

    private function linkFileToNewJob(DownloadedFile $file, string $ownerSubject): DownloadJob
    {
        $entityManager = $this->entityManager();
        $job = $this->newJob($entityManager, $ownerSubject, DownloadStateEnum::ALREADY_EXISTS);
        $job->addFile($entityManager->find(DownloadedFile::class, $file->getId()));

        $entityManager->persist($job);
        $entityManager->flush();

        $this->jobIds[] = $job->getId();

        return $job;
    }

    private function newJob(EntityManagerInterface $entityManager, string $ownerSubject, DownloadStateEnum $state): DownloadJob
    {
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $ownerSubject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$ownerSubject}', run `make prep-test`");

        return new DownloadJob()
            ->setUri('https://example.com/files-test-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState($state)
            ->setOwner($owner);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }

    /**
     * @return array{Authorization: string, Accept: string}
     */
    private function auth(string $user): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass'), 'Accept' => 'application/ld+json'];
    }
}
