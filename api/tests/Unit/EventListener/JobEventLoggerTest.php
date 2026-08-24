<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use App\EventListener\JobEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobEventLoggerTest extends TestCase
{
    private JobEventLogger $listener;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;
    private EntityManagerInterface|\PHPUnit\Framework\MockObject\MockObject $entityManager;
    private DownloadJob $downloadJob;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->listener = new JobEventLogger($this->logger, $this->entityManager);
        $this->downloadJob = new DownloadJob();
    }

    public function testListenerIsInstantiable(): void
    {
        $this->assertInstanceOf(JobEventLogger::class, $this->listener);
    }

    public function testOnJobPickedUpLogsAndStores(): void
    {
        $event = new JobPickedUpEvent($this->downloadJob, 'worker-123');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job picked up by worker', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobPickedUp($event);
    }

    public function testOnJobUpdateLogsAndStores(): void
    {
        $event = new JobUpdateEvent(
            $this->downloadJob,
            'Download started',
            ['progress' => 50]
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job update occurred', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobUpdate($event);
    }

    public function testOnJobCompletedLogsAndStores(): void
    {
        $metadata = ['filename' => 'video.mp4', 'size' => 1024000];
        $event = new JobCompletedEvent($this->downloadJob, $metadata);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job completed successfully', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobCompleted($event);
    }

    public function testOnJobFailedLogsAndStores(): void
    {
        $exception = new \Exception('Download failed');
        $event = new JobFailedEvent(
            $this->downloadJob,
            $exception,
            ['error_code' => 'TIMEOUT']
        );

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Job failed', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobFailed($event);
    }

    public function testOnJobFailedWithoutContext(): void
    {
        $exception = new \Exception('Network error');
        $event = new JobFailedEvent($this->downloadJob, $exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Job failed', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobFailed($event);
    }

    public function testOnJobUpdateWithoutContext(): void
    {
        $event = new JobUpdateEvent($this->downloadJob, 'Still processing');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job update occurred', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobUpdate($event);
    }

    public function testOnJobCompletedWithoutMetadata(): void
    {
        $event = new JobCompletedEvent($this->downloadJob);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job completed successfully', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobCompleted($event);
    }

    public function testDownloadJobStateIsSetToInProgressOnPickup(): void
    {
        $event = new JobPickedUpEvent($this->downloadJob, 'worker-123');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Job picked up by worker', $this->anything());

        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobPickedUp($event);
        $this->assertEquals(DownloadStateEnum::IN_PROGRESS, $this->downloadJob->getState());
    }
}
