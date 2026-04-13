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
use Doctrine\ORM\EntityNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DownloadJobHandlerTest extends TestCase
{
    private DownloadJobHandler $handler;
    private EntityManagerInterface $entityManager;
    private DownloadJobRepository $downloadJobRepository;
    private DownloaderFactory $downloaderFactory;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->downloadJobRepository = $this->createMock(DownloadJobRepository::class);
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];

        // Track dispatched events
        foreach ([JobPickedUpEvent::class, JobUpdateEvent::class, JobCompletedEvent::class, JobFailedEvent::class] as $eventClass) {
            $this->eventDispatcher->addListener($eventClass, function ($event) {
                $this->dispatchedEvents[] = $event;
            });
        }

        $this->entityManager->method('getRepository')
            ->with(DownloadJob::class)
            ->willReturn($this->downloadJobRepository);

        $this->handler = new DownloadJobHandler(
            $this->entityManager,
            $this->downloaderFactory,
            $this->logger,
            $this->eventDispatcher
        );
    }

    public function testSuccessfulDownloadWithSpecifiedDownloader(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setDownloader('mock');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->expects($this->atLeastOnce())
            ->method('getIdentifier')
            ->willReturn('mock');
        $mockDownloader->expects($this->once())
            ->method('download')
            ->willReturn(true);

        $this->downloadJobRepository->expects($this->once())
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('mock')
            ->willReturn($mockDownloader);

        $this->entityManager->expects($this->exactly(1))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->exactly(1))
            ->method('flush');

        $this->handler->__invoke($downloadJob);

        // Verify state changed to completed
        $this->assertSame(DownloadStateEnum::COMPLETED, $downloadJob->getState());

        // Verify events were dispatched
        $this->assertCount(3, $this->dispatchedEvents);
        $this->assertInstanceOf(JobPickedUpEvent::class, $this->dispatchedEvents[0]);
        $this->assertInstanceOf(JobUpdateEvent::class, $this->dispatchedEvents[1]);
        $this->assertInstanceOf(JobCompletedEvent::class, $this->dispatchedEvents[2]);
    }

    public function testSuccessfulDownloadWithAutoSelectedDownloader(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->expects($this->atLeastOnce())
            ->method('getIdentifier')
            ->willReturn('auto-selected');
        $mockDownloader->expects($this->once())
            ->method('download')
            ->willReturn(true);

        $this->downloadJobRepository->expects($this->once())
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([$mockDownloader]);

        $this->entityManager->expects($this->exactly(2))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->exactly(2))
            ->method('flush');

        $this->handler->__invoke($downloadJob);

        // Verify downloader was set and state changed
        $this->assertSame('auto-selected', $downloadJob->getDownloader());
        $this->assertSame(DownloadStateEnum::COMPLETED, $downloadJob->getState());

        // Verify correct events were dispatched
        $this->assertCount(3, $this->dispatchedEvents);
        $this->assertInstanceOf(JobPickedUpEvent::class, $this->dispatchedEvents[0]);
        $this->assertInstanceOf(JobUpdateEvent::class, $this->dispatchedEvents[1]);
        $this->assertInstanceOf(JobCompletedEvent::class, $this->dispatchedEvents[2]);
    }

    public function testJobNotFoundInDatabase(): void
    {
        $downloadJob = new DownloadJob();

        $this->downloadJobRepository->expects($this->once())
            ->method('find')
            ->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage('DownloadJob not found with ID:');

        $this->handler->__invoke($downloadJob);
    }

    public function testInvalidDownloaderSpecified(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setDownloader('invalid-downloader');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $this->downloadJobRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('invalid-downloader')
            ->willReturn(null);

        $this->entityManager->expects($this->exactly(1))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->exactly(1))
            ->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid downloader specified: invalid-downloader');

        $this->handler->__invoke($downloadJob);

        // Verify state changed to failed and failed event was dispatched
        $this->assertSame(DownloadStateEnum::FAILED, $downloadJob->getState());
        $failedEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobFailedEvent);
        $this->assertCount(1, $failedEvents);
    }

    public function testNoDownloaderFoundForUri(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://unsupported.com/test.zip');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $this->downloadJobRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([]);

        $this->entityManager->expects($this->exactly(1))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->exactly(1))
            ->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No downloader found for URI: https://unsupported.com/test.zip');

        $this->handler->__invoke($downloadJob);

        // Verify job failed properly
        $this->assertSame(DownloadStateEnum::FAILED, $downloadJob->getState());
    }

    public function testDownloaderThrowsException(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setDownloader('mock');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->expects($this->atLeastOnce())
            ->method('getIdentifier')
            ->willReturn('mock');
        $mockDownloader->expects($this->once())
            ->method('download')
            ->willThrowException(new \RuntimeException('Download failed'));

        $this->downloadJobRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('mock')
            ->willReturn($mockDownloader);

        $this->entityManager->expects($this->exactly(1))
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->exactly(1))
            ->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Download failed');

        $this->handler->__invoke($downloadJob);

        // Verify job failed and events were dispatched
        $this->assertSame(DownloadStateEnum::FAILED, $downloadJob->getState());
        $failedEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobFailedEvent);
        $this->assertCount(1, $failedEvents);

        $failedEvent = array_values($failedEvents)[0];
        $this->assertSame($downloadJob, $failedEvent->getDownloadJob());
        $this->assertInstanceOf(\RuntimeException::class, $failedEvent->getException());
    }

    public function testJobUpdateEventContentWithSpecifiedDownloader(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setDownloader('specified-mock');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('specified-mock');
        $mockDownloader->method('download')->willReturn(true);

        $this->downloadJobRepository->expects($this->once())
            ->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->willReturn($mockDownloader);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->handler->__invoke($downloadJob);

        // Find the JobUpdateEvent
        $updateEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobUpdateEvent);
        $this->assertCount(1, $updateEvents);

        $updateEvent = array_values($updateEvents)[0];
        $this->assertStringContainsString('Using specified downloader', $updateEvent->getUpdateMessage());
        $this->assertSame(['downloader' => 'specified-mock'], $updateEvent->getContext());
    }

    public function testJobUpdateEventContentWithAutoSelectedDownloader(): void
    {
        $downloadJob = new DownloadJob();
        $downloadJob->setUri('https://example.com/test.zip');
        $downloadJob->setState(DownloadStateEnum::PENDING);

        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('auto-selected');
        $mockDownloader->method('download')->willReturn(true);

        $this->downloadJobRepository->method('find')
            ->willReturn($downloadJob);

        $this->downloaderFactory->method('getDownloadersByDownloadJob')
            ->willReturn([$mockDownloader]);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->handler->__invoke($downloadJob);

        // Find the JobUpdateEvent
        $updateEvents = array_filter($this->dispatchedEvents, fn ($event) => $event instanceof JobUpdateEvent);
        $this->assertCount(1, $updateEvents);

        $updateEvent = array_values($updateEvents)[0];
        $this->assertStringContainsString('Downloader selected and job state updated to IN_PROGRESS', $updateEvent->getUpdateMessage());
        $this->assertSame(['downloader' => 'auto-selected'], $updateEvent->getContext());
    }
}
