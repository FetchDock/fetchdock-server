<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CookieDTO;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Enum\DownloadStateEnum;
use App\Enum\JobTypeEnum;
use App\Factory\DownloaderFactory;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadJobRepository;
use App\Repository\OidcSubjectIdentifierRepository;
use App\Service\Downloader\DownloaderInterface;
use App\State\DownloadJobQueuedProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class DownloadJobQueuedProcessorTest extends TestCase
{
    private DownloadJobQueuedProcessor $processor;
    private ProcessorInterface $persistProcessor;
    private ProcessorInterface $messengerProcessor;
    private DownloaderFactory $downloaderFactory;
    private LoggerInterface $logger;
    private TagAwareCacheInterface $cache;
    private Operation $operation;
    private Security $security;
    private DownloadJobRepository $downloadJobRepository;
    private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->persistProcessor = $this->createMock(ProcessorInterface::class);
        $this->messengerProcessor = $this->createMock(ProcessorInterface::class);
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->security = $this->createMock(Security::class);
        $this->downloadJobRepository = $this->createMock(DownloadJobRepository::class);
        $this->oidcSubjectIdentifierRepository = $this->createMock(OidcSubjectIdentifierRepository::class);

        $this->processor = new DownloadJobQueuedProcessor(
            $this->persistProcessor,
            $this->messengerProcessor,
            $this->logger,
            $this->downloaderFactory,
            $this->cache,
            $this->security,
            $this->oidcSubjectIdentifierRepository,
            $this->downloadJobRepository
        );
    }

    public function testProcessWithValidDownloaderSpecified(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/test.zip';
        $data->downloader = 'mock';
        $data->userAgent = 'TestAgent/1.0';
        $data->cookies = [new CookieDTO()];

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('mock')
            ->willReturn(true);

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(function (DownloadJob $job) {
                    return 'https://example.com/test.zip' === $job->getUri()
                        && 'mock' === $job->getDownloader()
                        && 'TestAgent/1.0' === $job->getUserAgent()
                        && is_array($job->getCookies())
                            && 1 === count($job->getCookies())
                            && $job->getCookies()[0] instanceof CookieDTO
                        && DownloadStateEnum::PENDING === $job->getState();
                }),
                $this->operation,
                [],
                []
            )
            ->willReturnCallback(function (DownloadJob $job) {
                // Simulate ID assignment after persistence
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 123);

                return $job;
            });

        $this->messengerProcessor->expects($this->once())
            ->method('process');

        $result = $this->processor->process($data, $this->operation);

        $this->assertInstanceOf(JobAcceptedDTO::class, $result);
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid()); // Valid UUID format
        $this->assertIsString($result->getToken());
        $this->assertSame(64, strlen($result->getToken()));
        $this->assertSame(JobTypeEnum::DOWNLOAD, $result->getJobType());
    }

    public function testProcessWithInvalidDownloaderSpecified(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/test.zip';
        $data->downloader = 'invalid-downloader';

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('mock');

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('invalid-downloader')
            ->willReturn(false);

        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn(['mock' => $mockDownloader]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid downloader specified. Possible values: mock');

        $this->processor->process($data, $this->operation);
    }

    public function testProcessWithAutoDownloaderSelectionFromCache(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/test.zip';

        $this->cache->expects($this->once())
            ->method('get')
            ->with('dlsupport_example.com')
            ->willReturn('cached-downloader');

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->willReturnCallback(function (DownloadJob $job) {
                $this->assertSame('cached-downloader', $job->getDownloader());
                // Simulate ID assignment
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 456);

                return $job;
            });

        $this->messengerProcessor->expects($this->once())
            ->method('process');

        $result = $this->processor->process($data, $this->operation);

        $this->assertInstanceOf(JobAcceptedDTO::class, $result);
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid());
        $this->assertIsString($result->getToken());
    }

    public function testProcessWithAutoDownloaderSelectionNotCached(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/test.zip';

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('auto-selected');

        $this->cache->expects($this->once())
            ->method('get')
            ->with('dlsupport_example.com')
            ->willReturnCallback(function ($key, $callback) use ($mockDownloader) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->atLeastOnce())->method('expiresAfter');
                $item->expects($this->once())->method('tag')->with([
                    'dlsupport',
                    'dlsupport_example.com',
                ]);

                $this->downloaderFactory->expects($this->once())
                    ->method('getDownloadersByDownloadJob')
                    ->with($this->callback(function (DownloadJobInterface $downloadJob) {
                        return 'example.com' === $downloadJob->getUrl()->getHost();
                    }))
                    ->willReturn([$mockDownloader]);

                return $callback($item);
            });

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->willReturnCallback(function (DownloadJob $job) {
                $this->assertSame('auto-selected', $job->getDownloader());
                // Simulate ID assignment
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 789);

                return $job;
            });

        $this->messengerProcessor->expects($this->once())
            ->method('process');

        $result = $this->processor->process($data, $this->operation);

        $this->assertInstanceOf(JobAcceptedDTO::class, $result);
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid());
        $this->assertIsString($result->getToken());
    }

    public function testProcessWithNoDownloaderFound(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://unsupported.com/test.zip';

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('mock');

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->atLeastOnce())->method('expiresAfter');
                $item->expects($this->once())->method('tag');

                $this->downloaderFactory->expects($this->once())
                    ->method('getDownloadersByDownloadJob')
                    ->willReturn([]); // No downloaders found

                return $callback($item);
            });

        $this->cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['dlsupport_unsupported.com']);

        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn(['mock' => $mockDownloader]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('No downloader found for the given URI. Possible values: mock');

        $this->processor->process($data, $this->operation);
    }

    public function testProcessWithInvalidUri(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'invalid-uri';
        $data->downloader = 'mock';

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('mock')
            ->willReturn(true);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid URI');

        $this->processor->process($data, $this->operation);
    }

    public function testProcessWithMinimalData(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/minimal.zip';
        $data->downloader = 'mock';

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->willReturn(true);

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(function (DownloadJob $job) {
                    return 'https://example.com/minimal.zip' === $job->getUri()
                        && 'mock' === $job->getDownloader()
                        && null === $job->getUserAgent()
                        && [] === $job->getCookies()
                        && DownloadStateEnum::PENDING === $job->getState();
                })
            )
            ->willReturnCallback(function (DownloadJob $job) {
                // Simulate ID assignment
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 999);

                return $job;
            });

        $this->messengerProcessor->expects($this->once())
            ->method('process');

        $result = $this->processor->process($data, $this->operation);

        $this->assertInstanceOf(JobAcceptedDTO::class, $result);
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid());
        $this->assertIsString($result->getToken());
        $this->assertSame(JobTypeEnum::DOWNLOAD, $result->getJobType());
    }

    public function testGetCacheKeyGeneration(): void
    {
        // This is a private method test through behavior verification
        $data1 = new DownloadJobDTO();
        $data1->uri = 'https://example.com/test1.zip';
        $data1->downloader = 'mock';

        $data2 = new DownloadJobDTO();
        $data2->uri = 'https://example.com/test2.zip';
        $data2->downloader = 'mock';

        $this->downloaderFactory->method('isValidDownloader')->willReturn(true);

        // Both should use the same cache key since they're from the same domain
        $this->cache->expects($this->never())->method('get');

        $this->persistProcessor->method('process')
            ->willReturnCallback(function (DownloadJob $job) {
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 1);

                return $job;
            });

        $this->messengerProcessor->method('process');

        $this->processor->process($data1, $this->operation);
        $this->processor->process($data2, $this->operation);
    }

    public function testProcessReusesExistingFilesOfPreviousDownload(): void
    {
        $existingFile = $this->createTemporaryFile();
        $previousJob = $this->createPreviousJob('yt-dlp-cli', [$existingFile, '/nonexistent/removed-file.mp4']);
        $previousJob->addDownloadJobEvent(new DownloadJobEvent()
            ->setEvent('process.stopped')
            ->setSource('listener')
            ->setContext(['command' => 'yt-dlp --cookies /tmp/user-a-cookies.txt']));

        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/video';
        $data->userAgent = 'UserB/1.0';

        $this->downloadJobRepository->expects($this->once())
            ->method('findReusableByUri')
            ->with('https://example.com/video')
            ->willReturn([$previousJob]);

        // No probing and no queued download: the job is complete on creation
        $this->downloaderFactory->expects($this->never())->method('getDownloadersByDownloadJob');
        $this->cache->expects($this->never())->method('get');
        $this->messengerProcessor->expects($this->never())->method('process');

        $persistedJob = null;
        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->willReturnCallback(function (DownloadJob $job) use (&$persistedJob) {
                $persistedJob = $job;

                return $job;
            });

        $result = $this->processor->process($data, $this->operation);

        $this->assertInstanceOf(DownloadJob::class, $persistedJob);
        $this->assertNotSame($previousJob, $persistedJob);
        $this->assertSame($persistedJob->getUuid()->toRfc4122(), $result->getJobUuid());
        $this->assertSame(DownloadStateEnum::ALREADY_EXISTS, $persistedJob->getState());
        $this->assertSame('yt-dlp-cli', $persistedJob->getDownloader());
        $this->assertSame('UserB/1.0', $persistedJob->getUserAgent());

        // Only files that still exist on disk are linked, on both sides of the relation
        $this->assertCount(1, $persistedJob->getFiles());
        $linkedFile = $persistedJob->getFiles()->first();
        $this->assertSame($existingFile, $linkedFile->getPath());
        $this->assertTrue($linkedFile->getDownloadJob()->contains($persistedJob));
        $this->assertTrue($linkedFile->getDownloadJob()->contains($previousJob));

        // The previous job's event log is not copied, only a single "loaded from cache" event is added
        $this->assertCount(1, $persistedJob->getDownloadJobEvents());
        $event = $persistedJob->getDownloadJobEvents()->first();
        $this->assertSame('job.loaded_from_cache', $event->getEvent());
        $this->assertSame('Previously downloaded job. Loaded from cache', $event->getUpdateMessage());
        $this->assertSame(['files' => 1], $event->getContext());
    }

    public function testProcessRestoresSoftDeletedFilesStillOnDisk(): void
    {
        $existingFile = $this->createTemporaryFile();
        $previousJob = $this->createPreviousJob('yt-dlp-cli', [$existingFile]);
        $previousJob->setDeletedAt(new \DateTime());
        $softDeletedFile = $previousJob->getFiles()->first();
        $softDeletedFile->setDeletedAt(new \DateTime());

        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/video';

        $this->downloadJobRepository->method('findReusableByUri')->willReturn([$previousJob]);
        $this->messengerProcessor->expects($this->never())->method('process');
        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with($this->callback(fn (DownloadJob $job) => DownloadStateEnum::ALREADY_EXISTS === $job->getState()
                && $job->getFiles()->contains($softDeletedFile)));

        $this->processor->process($data, $this->operation);

        $this->assertFalse($softDeletedFile->isDeleted());
        $this->assertNull($softDeletedFile->getDeletedAt());
    }

    public function testProcessDownloadsAgainWhenPreviousFilesNoLongerExist(): void
    {
        $previousJob = $this->createPreviousJob('mock', ['/nonexistent/removed-file.mp4']);

        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/video';
        $data->downloader = 'mock';

        $this->downloaderFactory->method('isValidDownloader')->willReturn(true);
        $this->downloadJobRepository->method('findReusableByUri')->willReturn([$previousJob]);

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with($this->callback(fn (DownloadJob $job) => DownloadStateEnum::PENDING === $job->getState()
                && $job->getFiles()->isEmpty()
                && $job->getDownloadJobEvents()->isEmpty()));
        $this->messengerProcessor->expects($this->once())->method('process');

        $this->processor->process($data, $this->operation);
    }

    public function testProcessWithForceDoesNotReusePreviousDownload(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/video';
        $data->downloader = 'mock';
        $data->force = true;

        $this->downloaderFactory->method('isValidDownloader')->willReturn(true);
        $this->downloadJobRepository->expects($this->never())->method('findReusableByUri');

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with($this->callback(fn (DownloadJob $job) => DownloadStateEnum::PENDING === $job->getState()));
        $this->messengerProcessor->expects($this->once())->method('process');

        $this->processor->process($data, $this->operation);
    }

    public function testProcessDoesNotReusePreviousDownloadOfDifferentDownloader(): void
    {
        $previousJob = $this->createPreviousJob('gallery-dl-cli', [$this->createTemporaryFile()]);

        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/video';
        $data->downloader = 'mock';

        $this->downloaderFactory->method('isValidDownloader')->willReturn(true);
        $this->downloadJobRepository->method('findReusableByUri')->willReturn([$previousJob]);

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with($this->callback(fn (DownloadJob $job) => DownloadStateEnum::PENDING === $job->getState()
                && 'mock' === $job->getDownloader()
                && $job->getFiles()->isEmpty()));
        $this->messengerProcessor->expects($this->once())->method('process');

        $this->processor->process($data, $this->operation);
    }

    /**
     * @param list<string> $filePaths
     */
    private function createPreviousJob(string $downloader, array $filePaths): DownloadJob
    {
        $previousJob = new DownloadJob()
            ->setUri('https://example.com/video')
            ->setDownloader($downloader)
            ->setState(DownloadStateEnum::COMPLETED);

        foreach ($filePaths as $filePath) {
            $previousJob->addFile(new DownloadedFile()->setPath($filePath)->setVisible(true));
        }

        return $previousJob;
    }

    private function createTemporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reuse-test-');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
