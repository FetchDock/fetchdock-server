<?php

namespace App\Tests\Integration\Workflow;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Symfony\Messenger\Processor as MessengerProcessor;
use App\Dto\DownloadJobDTO;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Factory\DownloaderFactory;
use App\Handler\DownloadJobHandler;
use App\Repository\DownloadJobRepository;
use App\Service\Downloader\MockDownloader;
use App\State\DownloadJobQueuedProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Integration test for the complete download workflow
 * From DTO processing through to job handling
 */
class DownloadWorkflowIntegrationTest extends TestCase
{
    private DownloadJobQueuedProcessor $processor;
    private DownloadJobHandler $handler;
    private DownloaderFactory $downloaderFactory;
    private MockDownloader $mockDownloader;
    private EntityManagerInterface $entityManager;
    private DownloadJobRepository $repository;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private TagAwareCacheInterface $cache;
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->mockDownloader = new MockDownloader();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->downloaderFactory = new DownloaderFactory([$this->mockDownloader], $this->logger);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(DownloadJobRepository::class);
        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];

        // Track events
        $this->eventDispatcher->addListener('*', function ($event) {
            $this->dispatchedEvents[] = $event;
        });

        $this->entityManager->method('getRepository')
            ->with(DownloadJob::class)
            ->willReturn($this->repository);

        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $this->processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $this->handler = new DownloadJobHandler(
            $this->entityManager,
            $this->downloaderFactory,
            $this->logger,
            $this->eventDispatcher
        );
    }

    public function testCompleteWorkflowWithValidDownloader(): void
    {
        // Step 1: Process DTO with specified downloader
        $dto = new DownloadJobDTO();
        $dto->uri = 'https://example.com/test.zip';
        $dto->downloader = 'mock';
        $dto->userAgent = 'TestAgent/1.0';

        $operation = $this->createMock(Operation::class);

        // Mock persistence to return job with ID
        $persistedJob = null;
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->method('process')
            ->willReturnCallback(function (DownloadJob $job) use (&$persistedJob) {
                $persistedJob = $job;
                // Simulate ID assignment
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 123);
                return $job;
            });

        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $result = $processor->process($dto, $operation);

        // Verify DTO processing
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid());
        $this->assertIsString($result->getToken());
        $this->assertSame(64, strlen($result->getToken()));
        $this->assertNotNull($persistedJob);
        $this->assertSame('https://example.com/test.zip', $persistedJob->getUri());
        $this->assertSame('mock', $persistedJob->getDownloader());
        $this->assertSame('TestAgent/1.0', $persistedJob->getUserAgent());
        $this->assertSame(DownloadStateEnum::PENDING, $persistedJob->getState());

        // Step 2: Simulate job handling
        $this->repository->method('find')
            ->with($persistedJob->getId())
            ->willReturn($persistedJob);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($persistedJob);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->handler->__invoke($persistedJob);

        // Verify final job state
        $this->assertSame(DownloadStateEnum::COMPLETED, $persistedJob->getState());
        $this->assertSame('mock', $persistedJob->getDownloader());
    }

    public function testWorkflowWithAutoDownloaderSelection(): void
    {
        // Step 1: Process DTO without specifying downloader
        $dto = new DownloadJobDTO();
        $dto->uri = 'https://example.com/auto-select.zip';

        $operation = $this->createMock(Operation::class);

        // Mock cache to return no cached downloader
        $this->cache->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(\Symfony\Contracts\Cache\ItemInterface::class);
                $item->method('expiresAfter');
                $item->method('tag');
                return $callback($item);
            });

        $persistedJob = null;
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->method('process')
            ->willReturnCallback(function (DownloadJob $job) use (&$persistedJob) {
                $persistedJob = $job;
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 456);
                return $job;
            });

        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $result = $processor->process($dto, $operation);

        // Verify auto-selection worked
        $this->assertIsString($result->getJobUuid());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->getJobUuid());
        $this->assertIsString($result->getToken());
        $this->assertSame(64, strlen($result->getToken()));
        $this->assertSame('mock', $persistedJob->getDownloader());

        // Step 2: Handle the job
        $this->repository->method('find')
            ->with(456)
            ->willReturn($persistedJob);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->handler->__invoke($persistedJob);

        $this->assertSame(DownloadStateEnum::COMPLETED, $persistedJob->getState());
    }

    public function testWorkflowWithUnsupportedUri(): void
    {
        $dto = new DownloadJobDTO();
        $dto->uri = 'https://unsupported.com/test.zip';

        $operation = $this->createMock(Operation::class);

        // Mock cache to simulate no downloader found
        $this->cache->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(\Symfony\Contracts\Cache\ItemInterface::class);
                $item->method('expiresAfter');
                $item->method('tag');
                return $callback($item);
            });

        $this->cache->expects($this->once())
            ->method('invalidateTags');

        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $this->expectExceptionMessage('No downloader found for the given URI');

        $processor->process($dto, $operation);
    }

    public function testWorkflowWithDownloadFailure(): void
    {
        // Create a failing downloader
        $failingDownloader = new class extends MockDownloader {
            public function getIdentifier(): string
            {
                return 'failing-mock';
            }

            public function download(\App\Model\DownloadJobInterface $downloadJob): true
            {
                throw new \RuntimeException('Download failed');
            }
        };

        $downloaderFactory = new DownloaderFactory([$failingDownloader], $this->logger);

        $handler = new DownloadJobHandler(
            $this->entityManager,
            $downloaderFactory,
            $this->logger,
            $this->eventDispatcher
        );

        $job = new DownloadJob();
        $job->setUri('https://example.com/failing.zip');
        $job->setDownloader('failing-mock');
        $job->setState(DownloadStateEnum::PENDING);

        // Simulate ID
        $reflection = new \ReflectionClass($job);
        $idProperty = $reflection->getProperty('id');

        $idProperty->setValue($job, 789);

        $this->repository->method('find')
            ->with(789)
            ->willReturn($job);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($job);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download failed');

        $handler->__invoke($job);

        // Verify job marked as failed
        $this->assertSame(DownloadStateEnum::FAILED, $job->getState());
    }

    public function testWorkflowValidatesDownloaderFactory(): void
    {
        // Test that the workflow properly uses the factory for validation
        $dto = new DownloadJobDTO();
        $dto->uri = 'https://example.com/test.zip';
        $dto->downloader = 'invalid-downloader';

        $operation = $this->createMock(Operation::class);

        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
        $this->expectExceptionMessage('Invalid downloader specified');

        $processor->process($dto, $operation);
    }

    public function testWorkflowMaintainsJobState(): void
    {
        // Test that job state is properly maintained throughout the workflow
        $dto = new DownloadJobDTO();
        $dto->uri = 'https://example.com/state-test.zip';
        $dto->downloader = 'mock';

        $operation = $this->createMock(Operation::class);

        $job = null;
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->method('process')
            ->willReturnCallback(function (DownloadJob $downloadJob) use (&$job) {
                $job = $downloadJob;
                $reflection = new \ReflectionClass($job);
                $idProperty = $reflection->getProperty('id');

                $idProperty->setValue($job, 999);
                return $job;
            });

        $messengerProcessor = $this->createMock(ProcessorInterface::class);

        $processor = new DownloadJobQueuedProcessor(
            $persistProcessor,
            $messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );

        $processor->process($dto, $operation);

        // Verify initial state
        $this->assertSame(DownloadStateEnum::PENDING, $job->getState());
        $this->assertSame('mock', $job->getDownloader());

        // Process through handler
        $this->repository->method('find')->willReturn($job);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->handler->__invoke($job);

        // Verify final state
        $this->assertSame(DownloadStateEnum::COMPLETED, $job->getState());
    }
}
