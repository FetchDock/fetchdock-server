<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\Site;
use App\Enum\DownloadStateEnum;
use App\Handler\MoveFileHandler;
use App\Handler\StartFileMoveBatchHandler;
use App\Message\MoveFileMessage;
use App\Message\StartFileMoveBatchMessage;
use App\Repository\DownloadedFileRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * #109 PR 3: tracked file moves, from the admin API through the worker's handlers to the public download URLs.
 * The file-moves transport is in-memory in tests: runMoves() plays the worker.
 */
class AdminFileMovesTest extends ApiTestCase
{
    use SiteTestTrait;

    private Client $client;
    private string $root;
    private DownloadDirectory $source;
    private DownloadDirectory $target;
    /** @var list<int> */
    private array $jobIds = [];
    /** @var list<object> file-moves messages taken from the in-memory transport, which each request resets */
    private array $queued = [];

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
        $this->root = static::getContainer()->getParameter('kernel.project_dir').'/var/test-download-roots/'.bin2hex(random_bytes(4));
        mkdir($this->root.'/a', 0o777, true);
        mkdir($this->root.'/b', 0o777, true);

        $entityManager = $this->entityManager();
        $this->source = new DownloadDirectory('Moves '.basename($this->root).' a', $this->root.'/a');
        $this->target = new DownloadDirectory('Moves '.basename($this->root).' b', $this->root.'/b');
        $entityManager->persist($this->source);
        $entityManager->persist($this->target);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager()->getConnection();
        $directoryIds = [$this->source->getId(), $this->target->getId()];
        $types = [ArrayParameterType::INTEGER];
        $connection->executeStatement('DELETE FROM file_move_batch WHERE source_directory_id IN (?) OR target_directory_id IN (?)', [$directoryIds, $directoryIds], [...$types, ...$types]);
        $connection->executeStatement('UPDATE site SET download_directory_id = NULL WHERE download_directory_id IN (?)', [$directoryIds], $types);
        $connection->executeStatement('DELETE FROM downloaded_file_download_job WHERE download_job_id IN (?) OR downloaded_file_id IN (SELECT id FROM downloaded_file WHERE directory_id IN (?))', [$this->jobIds ?: [0], $directoryIds], [...$types, ...$types]);
        $connection->executeStatement('DELETE FROM downloaded_file WHERE directory_id IN (?)', [$directoryIds], $types);
        $connection->executeStatement('DELETE FROM download_job WHERE id IN (?)', [$this->jobIds ?: [0]], $types);
        $connection->executeStatement('DELETE FROM download_directory WHERE id IN (?)', [$directoryIds], $types);
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public static function adminOnlyRequests(): iterable
    {
        yield ['GET', '/admin/file_moves'];
        yield ['POST', '/admin/sites/vimeo/file-moves'];
        yield ['POST', '/admin/file_moves/1/cancel'];
        yield ['POST', '/admin/download_directories/1/file-moves'];
    }

    #[DataProvider('adminOnlyRequests')]
    public function testNonAdminsAreForbidden(string $method, string $uri): void
    {
        $this->request($method, $uri, 'GET' === $method ? null : [], 'user');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDirectoryBatchMovesFilesAndKeepsDownloadUrls(): void
    {
        [$job, $file] = $this->createJobWithFile('site/video.mp4', 'video');
        file_put_contents($this->root.'/a/site/video.info.json', '{}');
        [, $busyFile] = $this->createJobWithFile('site/busy.mp4', 'busy', state: DownloadStateEnum::IN_PROGRESS);
        $site = $this->entityManager()->getRepository(Site::class)->findOneBy(['slug' => 'vimeo'])->setDownloadDirectory($this->source);
        $this->entityManager()->flush();

        $batch = $this->request('POST', $this->iri($this->source).'/file-moves', ['targetDirectory' => $this->iri($this->target)])->toArray();
        $this->assertResponseStatusCodeSame(202);
        $this->assertSame('directory', $batch['scope']);
        $this->assertSame('queued', $batch['status']);
        $this->assertSame($this->iri($this->source), $batch['sourceDirectory']);
        $this->assertSame([2, 1], [$batch['total'], $batch['skipped']], 'The file of the job in progress is skipped');
        $this->assertSame($this->iri($this->target), $this->request('GET', '/admin/sites/vimeo/storage')->toArray()['directory'], 'reassignSites defaults to true');

        // Before the move, the file is served from the source
        $downloadUri = sprintf('/download/%s/%s/files/%d', $job->getUuid()->toRfc4122(), $job->getToken(), $file->getId());
        $this->assertSame('video', $this->download($downloadUri));

        $this->runMoves();

        $batch = $this->request('GET', $batch['@id'])->toArray();
        $this->assertSame('completed', $batch['status']);
        $this->assertSame([2, 1, 1, 0], [$batch['total'], $batch['moved'], $batch['skipped'], $batch['failed']]);
        $this->assertSame([5, 5], [$batch['bytesTotal'], $batch['bytesMoved']]);
        $this->assertNotNull($batch['finishedAt']);

        $this->assertFileExists($this->root.'/b/site/video.mp4');
        $this->assertFileDoesNotExist($this->root.'/a/site/video.mp4');
        $this->assertFileExists($this->root.'/b/site/video.info.json', 'The untracked sidecar moves along');
        $this->assertFileExists($this->root.'/a/site/busy.mp4');
        $moved = $this->request('GET', '/admin/downloaded_files/'.$file->getId())->toArray();
        $this->assertSame([$this->iri($this->target), 'site/video.mp4'], [$moved['directory'], $moved['relativePath']]);
        $this->assertSame($this->iri($this->source), $this->request('GET', '/admin/downloaded_files/'.$busyFile->getId())->toArray()['directory']);

        // The public URL is unchanged, and the old location still leads to the file (app:scan:downloaded-files)
        $this->assertSame('video', $this->download($downloadUri));
        $this->assertSame($file->getId(), static::getContainer()->get(DownloadedFileRepository::class)->findMovedFrom($this->entityManager()->find(DownloadDirectory::class, $this->source->getId()), 'site/video.mp4')?->getId());

        // A redelivered message changes nothing
        $this->dispatch(new MoveFileMessage($this->itemIds($batch['id'])[0]));
        $this->runMoves();
        $this->assertSame(1, $this->request('GET', $batch['@id'])->toArray()['moved']);
    }

    public function testSiteBatchSkipsSharedFilesAndRejectsOverlappingBatches(): void
    {
        $this->createJobWithFile('own.mp4', 'own', site: 'vimeo');
        [, $shared] = $this->createJobWithFile('shared.mp4', 'shared', site: 'vimeo');
        $this->linkToNewJob($shared, 'youtube');

        $storage = $this->request('PUT', '/admin/sites/vimeo/storage', ['directory' => $this->iri($this->target), 'moveExisting' => true])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($storage['activeMove']);
        $batch = $this->request('GET', $storage['activeMove'])->toArray();
        $this->assertSame(['site', 'vimeo', 1], [$batch['scope'], $batch['site'], $batch['skipped']]);

        $this->request('POST', '/admin/sites/vimeo/file-moves', []);
        $this->assertResponseStatusCodeSame(409);
        $this->request('PUT', '/admin/sites/vimeo/storage', ['directory' => null, 'moveExisting' => true]);
        $this->assertResponseStatusCodeSame(409);
        $this->request('POST', $this->iri($this->target).'/file-moves', ['targetDirectory' => $this->iri($this->source)]);
        $this->assertResponseStatusCodeSame(409, 'A batch touching the directory is active');
        $this->request('PATCH', $this->iri($this->target), ['path' => $this->root.'/a', 'relocate' => true], merge: true);
        $this->assertResponseStatusCodeSame(409);

        $this->runMoves();

        $this->assertFileExists($this->root.'/b/own.mp4');
        $this->assertFileExists($this->root.'/a/shared.mp4', 'A file other sites use too stays');
        $this->assertSame('completed', $this->request('GET', $storage['activeMove'])->toArray()['status']);
        $this->assertNull($this->request('GET', '/admin/sites/vimeo/storage')->toArray()['activeMove']);
        $list = $this->request('GET', '/admin/file_moves?site=vimeo&status=completed')->toArray()['member'];
        $this->assertSame($storage['activeMove'], $list[0]['@id']);
    }

    public function testCancelLeavesTheRemainingFilesInPlace(): void
    {
        $this->createJobWithFile('one.mp4', 'one');
        $this->createJobWithFile('two.mp4', 'two');
        $batch = $this->request('POST', $this->iri($this->source).'/file-moves', ['targetDirectory' => $this->iri($this->target), 'reassignSites' => false])->toArray();

        $cancelled = $this->request('POST', $batch['@id'].'/cancel', [])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame(['cancelled', 2], [$cancelled['status'], $cancelled['skipped']]);

        $this->runMoves();
        $this->assertFileExists($this->root.'/a/one.mp4');
        $this->assertFileDoesNotExist($this->root.'/b/one.mp4');
        $this->assertSame(0, $this->request('GET', $batch['@id'])->toArray()['moved']);

        $this->request('POST', $batch['@id'].'/cancel', []);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testReportsMissingSourcesAndConflicts(): void
    {
        [, $missing] = $this->createJobWithFile('missing.mp4', 'gone');
        unlink($this->root.'/a/missing.mp4');
        $this->createJobWithFile('conflict.mp4', 'source');
        file_put_contents($this->root.'/b/conflict.mp4', 'other content');
        $batch = $this->request('POST', $this->iri($this->source).'/file-moves', ['targetDirectory' => $this->iri($this->target), 'reassignSites' => false])->toArray();

        $this->runMoves();

        $batch = $this->request('GET', $batch['@id'])->toArray();
        $this->assertSame(['completed_with_errors', 2], [$batch['status'], $batch['failed']]);
        $this->assertEqualsCanonicalizing(['missing source', 'conflict: the target exists'], array_column($batch['errors'], 'error'));
        $this->assertSame('other content', file_get_contents($this->root.'/b/conflict.mp4'), 'Never overwritten');
        $this->assertSame('source', file_get_contents($this->root.'/a/conflict.mp4'));
        $this->assertSame($this->iri($this->source), $this->request('GET', '/admin/downloaded_files/'.$missing->getId())->toArray()['directory']);
    }

    public function testRejectsInvalidDirectoryBatches(): void
    {
        $this->request('POST', $this->iri($this->source).'/file-moves', ['targetDirectory' => $this->iri($this->source)]);
        $this->assertResponseStatusCodeSame(422);
        $this->request('POST', $this->iri($this->source).'/file-moves', []);
        $this->assertResponseStatusCodeSame(422);
        $this->request('POST', '/admin/download_directories/999999999/file-moves', ['targetDirectory' => $this->iri($this->target)]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRecoverDispatchesItemsStuckInMovingAgain(): void
    {
        $this->createJobWithFile('stuck.mp4', 'stuck');
        $batch = $this->request('POST', $this->iri($this->source).'/file-moves', ['targetDirectory' => $this->iri($this->target), 'reassignSites' => false])->toArray();
        $this->handle(new StartFileMoveBatchMessage($batch['id']));
        $this->transport()->reset();
        $this->queued = [];
        // A worker died while moving the file
        $this->entityManager()->getConnection()->executeStatement("UPDATE file_move_item SET status = 'moving', updated_at = NOW() - INTERVAL '2 hours' WHERE batch_id = ?", [$batch['id']]);

        $tester = new CommandTester(new Application(static::$kernel)->find('app:file-moves:recover'));
        $tester->execute([]);
        $this->assertStringContainsString('Released 1 stuck items', $tester->getDisplay());

        $this->runMoves();
        $this->assertSame('completed', $this->request('GET', $batch['@id'])->toArray()['status']);
        $this->assertFileExists($this->root.'/b/stuck.mp4');
    }

    /**
     * Plays the worker: handles the file-moves messages, including the ones the handlers dispatch, until none is left.
     */
    private function runMoves(): void
    {
        $this->collectMessages();
        while ([] !== $this->queued) {
            $this->handle(array_shift($this->queued));
            // Messages the handlers dispatch
            $this->collectMessages();
        }
        $this->entityManager()->clear();
    }

    private function collectMessages(): void
    {
        $transport = $this->transport();
        foreach (iterator_to_array($transport->get(), false) as $envelope) {
            $transport->ack($envelope);
            $this->queued[] = $envelope->getMessage();
        }
    }

    private function handle(object $message): void
    {
        $handler = $message instanceof StartFileMoveBatchMessage ? StartFileMoveBatchHandler::class : MoveFileHandler::class;
        static::getContainer()->get($handler)($message);
    }

    private function dispatch(object $message): void
    {
        $this->queued[] = $message;
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.file-moves');
    }

    /** @return list<int> */
    private function itemIds(int $batchId): array
    {
        return array_map('intval', $this->entityManager()->getConnection()->fetchFirstColumn('SELECT id FROM file_move_item WHERE batch_id = ? ORDER BY id', [$batchId]));
    }

    /**
     * @return array{DownloadJob, DownloadedFile}
     */
    private function createJobWithFile(string $relativePath, string $content, ?string $site = null, DownloadStateEnum $state = DownloadStateEnum::COMPLETED): array
    {
        $path = $this->root.'/a/'.$relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);

        $entityManager = $this->entityManager();
        $file = new DownloadedFile()
            ->setLocation($entityManager->find(DownloadDirectory::class, $this->source->getId()), $relativePath)
            ->setVisible(true);
        $job = $this->newJob($site, $state);
        $job->addFile($file);
        $entityManager->persist($file);
        $entityManager->flush();
        $this->jobIds[] = (int) $job->getId();

        return [$job, $file];
    }

    private function linkToNewJob(DownloadedFile $file, string $site): void
    {
        $entityManager = $this->entityManager();
        $job = $this->newJob($site, DownloadStateEnum::COMPLETED);
        $job->addFile($entityManager->find(DownloadedFile::class, $file->getId()));
        $entityManager->flush();
        $this->jobIds[] = (int) $job->getId();
    }

    private function newJob(?string $site, DownloadStateEnum $state): DownloadJob
    {
        $entityManager = $this->entityManager();
        $job = new DownloadJob()
            ->setUri('https://example.com/moves-'.bin2hex(random_bytes(4)))
            ->setDownloader('mock')
            ->setState($state)
            ->setSite(null === $site ? null : $entityManager->getRepository(Site::class)->findOneBy(['slug' => $site]));
        $entityManager->persist($job);

        return $job;
    }

    private function download(string $uri): string
    {
        $this->client->request('GET', $uri);
        $this->collectMessages();
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getKernelBrowser()->getInternalResponse()->getContent()
            ?: (string) file_get_contents($this->client->getKernelBrowser()->getResponse()->getFile()->getPathname());
    }

    private function iri(DownloadDirectory $directory): string
    {
        return '/admin/download_directories/'.$directory->getId();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function request(string $method, string $uri, ?array $json = null, string $user = 'admin', bool $merge = false): ResponseInterface
    {
        $response = $this->client->request($method, $uri, [
            'json' => $json,
            'headers' => ['Content-Type' => $merge ? 'application/merge-patch+json' : 'application/ld+json', ...self::auth($user)],
        ]);
        $response->getStatusCode();
        $this->collectMessages();

        return $response;
    }
}
