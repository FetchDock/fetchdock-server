<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\Site;
use App\Enum\DownloadStateEnum;
use App\Repository\DownloadDirectoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * #109 PR 2: download directories and site storage in the admin API.
 */
class AdminDownloadDirectoriesTest extends ApiTestCase
{
    use SiteTestTrait;

    private Client $client;
    private string $root;
    private int $defaultId;
    /** @var list<int> */
    private array $jobIds = [];
    /** @var list<int> */
    private array $fileIds = [];

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
        // Inside the test DOWNLOAD_ROOTS entry (config/services.yaml, when@test)
        $this->root = static::getContainer()->getParameter('kernel.project_dir').'/var/test-download-roots/'.bin2hex(random_bytes(4));
        mkdir($this->root.'/one', 0o777, true);
        mkdir($this->root.'/two', 0o777, true);
        $this->defaultId = (int) static::getContainer()->get(DownloadDirectoryRepository::class)->findDefault()->getId();
    }

    protected function tearDown(): void
    {
        $entityManager = $this->entityManager();
        $repository = $entityManager->getRepository(DownloadDirectory::class);
        $repository->makeDefault($repository->find($this->defaultId));
        $directoryIds = array_map(static fn (DownloadDirectory $directory): ?int => $directory->getId(), array_filter(
            $repository->findAll(),
            fn (DownloadDirectory $directory): bool => str_starts_with($directory->getPath(), $this->root),
        ));
        if ([] !== $directoryIds) {
            $entityManager->createQuery('UPDATE '.Site::class.' s SET s.downloadDirectory = NULL WHERE s.downloadDirectory IN (:ids)')->execute(['ids' => $directoryIds]);
            $entityManager->getConnection()->executeStatement('DELETE FROM downloaded_file_download_job WHERE downloaded_file_id IN (SELECT id FROM downloaded_file WHERE directory_id IN (?))', [$directoryIds], [ArrayParameterType::INTEGER]);
            $entityManager->getConnection()->executeStatement('DELETE FROM downloaded_file WHERE directory_id IN (?)', [$directoryIds], [ArrayParameterType::INTEGER]);
            $entityManager->getConnection()->executeStatement('UPDATE download_job SET download_directory_id = NULL WHERE download_directory_id IN (?)', [$directoryIds], [ArrayParameterType::INTEGER]);
            $entityManager->getConnection()->executeStatement('DELETE FROM download_directory WHERE id IN (?)', [$directoryIds], [ArrayParameterType::INTEGER]);
        }
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM downloaded_file_download_job WHERE downloaded_file_id IN (?) OR download_job_id IN (?)', [$this->fileIds ?: [0], $this->jobIds ?: [0]], [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]);
        $connection->executeStatement('DELETE FROM downloaded_file WHERE id IN (?)', [$this->fileIds ?: [0]], [ArrayParameterType::INTEGER]);
        $connection->executeStatement('DELETE FROM download_job WHERE id IN (?)', [$this->jobIds ?: [0]], [ArrayParameterType::INTEGER]);
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public static function adminOnlyRequests(): iterable
    {
        yield ['GET', '/admin/download_directories'];
        yield ['POST', '/admin/download_directories'];
        yield ['GET', '/admin/sites/vimeo/storage'];
        yield ['PUT', '/admin/sites/vimeo/storage'];
    }

    #[DataProvider('adminOnlyRequests')]
    public function testNonAdminsAreForbidden(string $method, string $uri): void
    {
        $this->request($method, $uri, 'GET' === $method ? null : ['name' => 'x', 'path' => '/downloads'], 'user');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreatesListsAndDeletesADirectory(): void
    {
        $data = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/one/', 'verifyChecksum' => true])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($this->root.'/one', $data['path']);
        $this->assertFalse($data['isDefault']);
        $this->assertTrue($data['verifyChecksum']);
        $this->assertTrue($data['available']);
        $this->assertSame(0, $data['fileCount']);
        $this->assertArrayNotHasKey('relocate', $data);

        $list = $this->request('GET', '/admin/download_directories')->toArray()['member'];
        $this->assertTrue($list[0]['isDefault'], 'The default directory comes first');
        $this->assertContains($data['@id'], array_column($list, '@id'));

        $this->request('DELETE', $data['@id']);
        $this->assertResponseStatusCodeSame(204);
    }

    public static function invalidPaths(): iterable
    {
        yield 'relative' => ['relative/path', 'absolute'];
        yield 'outside the roots' => ['/tmp', 'DOWNLOAD_ROOTS'];
        yield 'missing' => ['{root}/missing', 'does not exist'];
        yield 'overlapping the default' => ['/downloads/{name}', 'overlaps'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path, string $message): void
    {
        if (str_contains($path, '{name}')) {
            // Needs to exist to reach the overlap check
            $path = '/downloads/'.basename($this->root);
            mkdir($path);
        }
        try {
            $response = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('bad'), 'path' => str_replace('{root}', $this->root, $path)]);

            $this->assertResponseStatusCodeSame(422);
            $this->assertStringContainsString($message, $response->toArray(false)['violations'][0]['message']);
            $this->assertSame('path', $response->toArray(false)['violations'][0]['propertyPath']);
        } finally {
            if (str_starts_with($path, '/downloads/')) {
                rmdir($path);
            }
        }
    }

    public function testRejectsNestedDirectoriesAndDuplicateNames(): void
    {
        $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/one']);
        mkdir($this->root.'/one/nested');

        $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('nested'), 'path' => $this->root.'/one/nested']);
        $this->assertResponseStatusCodeSame(422);

        $response = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/two']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('name', $response->toArray(false)['violations'][0]['propertyPath']);
    }

    public function testPathChangesOnlyWithoutFilesOrWithRelocate(): void
    {
        $directory = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/one'])->toArray();
        $fileId = $this->createFile($directory['id'], 'site/video.mp4');

        $response = $this->request('PATCH', $directory['@id'], ['path' => $this->root.'/two'], merge: true);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('relocate', $response->toArray(false)['violations'][0]['message']);

        $data = $this->request('PATCH', $directory['@id'], ['path' => $this->root.'/two', 'relocate' => true], merge: true)->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame($this->root.'/two', $data['path']);
        $this->assertSame(1, $data['fileCount']);

        $file = $this->request('GET', '/admin/downloaded_files/'.$fileId)->toArray();
        $this->assertSame($directory['@id'], $file['directory']);
        $this->assertSame('site/video.mp4', $file['relativePath']);
        $this->assertSame($this->root.'/two/site/video.mp4', $file['path']);

        $this->request('DELETE', $directory['@id']);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testSwitchesTheDefaultDirectory(): void
    {
        $directory = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/one'])->toArray();

        $data = $this->request('PATCH', $directory['@id'], ['isDefault' => true], merge: true)->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['isDefault']);
        $this->assertFalse($this->request('GET', '/admin/download_directories/'.$this->defaultId)->toArray()['isDefault']);

        $this->request('PATCH', $directory['@id'], ['isDefault' => false], merge: true);
        $this->assertResponseStatusCodeSame(422);
        $this->request('DELETE', $directory['@id']);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testSiteStorage(): void
    {
        $default = '/admin/download_directories/'.$this->defaultId;
        $data = $this->request('GET', '/admin/sites/vimeo/storage')->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('vimeo', $data['slug']);
        $this->assertNull($data['directory']);
        $this->assertSame($default, $data['effectiveDirectory']);

        $directory = $this->request('POST', '/admin/download_directories', ['name' => $this->directoryName('one'), 'path' => $this->root.'/one'])->toArray();
        $jobFile = $this->createFile($this->defaultId, 'vimeo-'.bin2hex(random_bytes(4)).'.mp4', 'vimeo');

        $data = $this->request('PUT', '/admin/sites/vimeo/storage', ['directory' => $directory['@id']])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame($directory['@id'], $data['directory']);
        $this->assertSame($directory['@id'], $data['effectiveDirectory']);
        $this->assertGreaterThanOrEqual(1, $data['filesElsewhere'], 'Existing files stay where they are');
        $this->assertSame(1, $this->request('GET', $directory['@id'])->toArray()['siteCount']);

        // A site in use keeps its directory
        $this->request('DELETE', $directory['@id']);
        $this->assertResponseStatusCodeSame(409);

        $data = $this->request('PUT', '/admin/sites/vimeo/storage', ['directory' => null])->toArray();
        $this->assertNull($data['directory']);
        $this->assertSame($default, $data['effectiveDirectory']);

        $this->request('PUT', '/admin/sites/vimeo/storage', ['directory' => '/admin/download_directories/999999999']);
        $this->assertResponseStatusCodeSame(400);
        $this->request('GET', '/admin/sites/unknown-site/storage');
        $this->assertResponseStatusCodeSame(404);
        $this->assertNotNull($jobFile);
    }

    public function testSubmissionStoresTheSite(): void
    {
        $response = $this->request('POST', '/download_jobs', ['uri' => 'https://vimeo.com/'.random_int(100000000, 999999999), 'downloader' => 'mock']);
        $this->assertResponseStatusCodeSame(202);

        $job = $this->entityManager()->getRepository(DownloadJob::class)->findOneBy(['uuid' => $response->toArray()['jobUuid']]);
        $this->jobIds[] = (int) $job->getId();
        $this->assertSame('vimeo', $job->getSite()?->getSlug());
        $this->assertNull($job->getDownloadDirectory(), 'The worker captures the directory when it starts the job');
    }

    private function createFile(int $directoryId, string $relativePath, ?string $siteSlug = null): int
    {
        $entityManager = $this->entityManager();
        $file = new DownloadedFile()->setLocation($entityManager->find(DownloadDirectory::class, $directoryId), $relativePath)->setVisible(true);
        if (null !== $siteSlug) {
            $job = new DownloadJob()
                ->setUri('https://example.com/'.$siteSlug)
                ->setDownloader('mock')
                ->setState(DownloadStateEnum::COMPLETED)
                ->setSite($entityManager->getRepository(Site::class)->findOneBy(['slug' => $siteSlug]));
            $job->addFile($file);
            $entityManager->persist($job);
        }
        $entityManager->persist($file);
        $entityManager->flush();
        $this->fileIds[] = (int) $file->getId();
        if (isset($job)) {
            $this->jobIds[] = (int) $job->getId();
        }

        return (int) $file->getId();
    }

    private function directoryName(string $suffix): string
    {
        return 'Test '.basename($this->root).' '.$suffix;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function request(string $method, string $uri, ?array $json = null, string $user = 'admin', bool $merge = false): ResponseInterface
    {
        return $this->client->request($method, $uri, [
            'json' => $json,
            'headers' => ['Content-Type' => $merge ? 'application/merge-patch+json' : 'application/ld+json', ...self::auth($user)],
        ]);
    }
}
