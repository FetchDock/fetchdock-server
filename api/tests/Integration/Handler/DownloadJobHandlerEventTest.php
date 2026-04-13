<?php

namespace App\Tests\Integration\Handler;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use App\Factory\DownloaderFactory;
use App\Handler\DownloadJobHandler;
use App\Repository\DownloadJobRepository;
use App\Service\Downloader\DownloaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DownloadJobHandlerEventTest extends TestCase
{
    private EventDispatcher $eventDispatcher;
    private array $dispatchedEvents = [];
    private DownloadJobHandler $handler;
    private EntityManagerInterface $entityManager;
    private DownloadJobRepository $downloadJobRepository;
    private DownloaderFactory $downloaderFactory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];

        // Track all dispatched events
        $this->eventDispatcher->addListener(JobPickedUpEvent::class, function (JobPickedUpEvent $event) {
            $this->dispatchedEvents[] = $event;
        });

        $this->eventDispatcher->addListener(JobUpdateEvent::class, function (JobUpdateEvent $event) {
            $this->dispatchedEvents[] = $event;
        });

        $this->eventDispatcher->addListener(JobCompletedEvent::class, function (JobCompletedEvent $event) {
            $this->dispatchedEvents[] = $event;
        });

        $this->eventDispatcher->addListener(JobFailedEvent::class, function (JobFailedEvent $event) {
            $this->dispatchedEvents[] = $event;
        });

        // Mock dependencies
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->downloadJobRepository = $this->createMock(DownloadJobRepository::class);
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new DownloadJobHandler(
            $this->entityManager,
            $this->downloaderFactory,
            $this->logger,
            $this->eventDispatcher
        );
    }

    public function testJobPickedUpEventIsDispatched(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setDownloader('mock');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        // Mock downloader
        $mockDownloader = $this->createMockDownloader();
        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('mock')
            ->willReturn($mockDownloader);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(DownloadJob::class)
            ->willReturn($this->downloadJobRepository);

        $this->downloadJobRepository
            ->expects($this->once())
            ->method('find')
            ->with($downloadJob->getId())
            ->willReturn($downloadJob);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->handler->__invoke($downloadJob);

        // Verify JobPickedUpEvent was dispatched
        $pickedUpEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobPickedUpEvent);
        $this->assertCount(1, $pickedUpEvents);

        $pickedUpEvent = array_shift($pickedUpEvents);
        $this->assertSame($downloadJob, $pickedUpEvent->getDownloadJob());

        // Verify JobCompletedEvent was also dispatched
        $completedEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobCompletedEvent);
        $this->assertCount(1, $completedEvents);
    }

    public function testJobUpdateEventIsDispatchedWhenDownloaderIsSelected(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        // Mock downloader selection
        $mockDownloader = $this->createMockDownloader();
        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([$mockDownloader]);

        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(DownloadJob::class)
            ->willReturn($this->downloadJobRepository);

        $this->downloadJobRepository
            ->expects($this->once())
            ->method('find')
            ->with($downloadJob->getId())
            ->willReturn($downloadJob);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        $this->handler->__invoke($downloadJob);

        // Verify JobUpdateEvent was dispatched
        $updateEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobUpdateEvent);
        $this->assertCount(1, $updateEvents);

        $updateEvent = array_shift($updateEvents);
        $this->assertSame($downloadJob, $updateEvent->getDownloadJob());
        $this->assertStringContainsString('Downloader selected', $updateEvent->getUpdateMessage());
        $this->assertArrayHasKey('downloader', $updateEvent->getContext());
    }

    public function testJobFailedEventIsDispatchedOnException(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        // Mock downloader to throw exception
        $exception = new \Exception('Download failed');
        $mockDownloader = $this->createMockDownloaderThatThrows($exception);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('getRepository')
            ->with(DownloadJob::class)
            ->willReturn($this->downloadJobRepository);

        $this->downloadJobRepository
            ->expects($this->exactly(2))
            ->method('find')
            ->with($downloadJob->getId())
            ->willReturn($downloadJob);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([$mockDownloader]);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Download failed');

        $this->handler->__invoke($downloadJob);

        // Verify JobFailedEvent was dispatched
        $failedEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobFailedEvent);
        $this->assertCount(1, $failedEvents);

        $failedEvent = array_shift($failedEvents);
        $this->assertSame($downloadJob, $failedEvent->getDownloadJob());
        $this->assertSame($exception, $failedEvent->getException());
        $this->assertSame(DownloadStateEnum::FAILED, $downloadJob->getState());
    }

    private function createMockDownloader()
    {
        $downloader = $this->createMock(DownloaderInterface::class);
        $downloader->method('getIdentifier')->willReturn('mock');
        $downloader->method('download')->willReturn(true);

        return $downloader;
    }

    private function createMockDownloaderThatThrows(\Exception $exception)
    {
        $downloader = $this->createMock(DownloaderInterface::class);
        $downloader->method('getIdentifier')->willReturn('mock');
        $downloader->method('download')->willThrowException($exception);

        return $downloader;
    }
}
