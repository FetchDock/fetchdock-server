<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
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
        // The job is stored (managed); storing an unmanaged copy is covered by testEventOfAnUnstoredJobIsSkipped
        $this->entityManager->method('contains')->willReturn(true);
        $this->listener = new JobEventLogger($this->logger, $this->entityManager);
        $this->downloadJob = new DownloadJob();
    }

    public function testEventOfAnUnmanagedJobCopyIsLinkedToTheStoredJob(): void
    {
        $copy = $this->createStub(DownloadJob::class);
        $copy->method('getId')->willReturn(42);
        $stored = new DownloadJob();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(false);
        $entityManager->expects($this->once())->method('find')->with(DownloadJob::class, 42)->willReturn($stored);
        // Only the event is persisted: persisting the copy would INSERT the existing job again (duplicate uuid)
        $entityManager->expects($this->once())->method('persist')->with($this->callback(
            static fn (DownloadJobEvent $event): bool => $event->getDownloadJob() === $stored,
        ));
        $entityManager->expects($this->once())->method('flush');

        new JobEventLogger($this->logger, $entityManager)->onJobFailed(new JobFailedEvent($copy, new \RuntimeException('download failed')));
    }

    public function testEventOfAnUnstoredJobIsSkipped(): void
    {
        $copy = $this->createStub(DownloadJob::class);
        $copy->method('getId')->willReturn(42);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(false);
        $entityManager->method('find')->willReturn(null);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');
        $this->logger->expects($this->once())->method('warning')->with('Not storing a download job event: the job is not stored');

        new JobEventLogger($this->logger, $entityManager)->onJobFailed(new JobFailedEvent($copy, new \RuntimeException('download failed')));
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
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

        $this->entityManager->expects($this->once())
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobCompleted($event);
    }
}
