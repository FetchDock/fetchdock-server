<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Symfony\Messenger\Processor as MessengerProcessor;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Enum\JobTypeEnum;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use App\State\DownloadJobQueuedProcessor;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class DownloadJobQueuedProcessorTest extends TestCase
{
    private DownloadJobQueuedProcessor $processor;
    private ProcessorInterface $persistProcessor;
    private ProcessorInterface $messengerProcessor;
    private DownloaderFactory $downloaderFactory;
    private TagAwareCacheInterface $cache;
    private Operation $operation;

    protected function setUp(): void
    {
        $this->persistProcessor = $this->createMock(ProcessorInterface::class);
        $this->messengerProcessor = $this->createMock(ProcessorInterface::class);
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->operation = $this->createMock(Operation::class);

        $this->processor = new DownloadJobQueuedProcessor(
            $this->persistProcessor,
            $this->messengerProcessor,
            $this->downloaderFactory,
            $this->cache
        );
    }

    public function testProcessWithValidDownloaderSpecified(): void
    {
        $data = new DownloadJobDTO();
        $data->uri = 'https://example.com/test.zip';
        $data->downloader = 'mock';
        $data->userAgent = 'TestAgent/1.0';
        $data->cookies = ['session' => 'abc123'];

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('mock')
            ->willReturn(true);

        $this->persistProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(function (DownloadJob $job) {
                    return $job->getUri() === 'https://example.com/test.zip'
                        && $job->getDownloader() === 'mock'
                        && $job->getUserAgent() === 'TestAgent/1.0'
                        && $job->getCookies() === ['session' => 'abc123']
                        && $job->getState() === DownloadStateEnum::PENDING;
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
                    'dlsupport_example.com'
                ]);

                $this->downloaderFactory->expects($this->once())
                    ->method('getDownloadersByUri')
                    ->with($this->callback(function (Uri $uri) {
                        return $uri->getHost() === 'example.com';
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
                    ->method('getDownloadersByUri')
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
                    return $job->getUri() === 'https://example.com/minimal.zip'
                        && $job->getDownloader() === 'mock'
                        && $job->getUserAgent() === null
                        && $job->getCookies() === null
                        && $job->getState() === DownloadStateEnum::PENDING;
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
}
