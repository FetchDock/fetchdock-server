<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Event\CliProcessStopEvent;
use App\EventListener\ProcessStoppedListener;
use App\Factory\DownloaderFactory;
use App\Repository\DownloadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class ProcessStoppedListenerTest extends TestCase
{
    private ProcessStoppedListener $listener;
    private EntityManagerInterface|MockObject $entityManager;
    private LoggerInterface|MockObject $logger;
    private DownloadJobRepository|MockObject $repository;
    private DownloaderFactory|MockObject $downloaderFactory;
    private DownloadJob $downloadJob;
    private Process|MockObject $process;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(DownloadJobRepository::class);
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->listener = new ProcessStoppedListener(
            $this->logger,
            $this->entityManager,
            $this->repository,
            $this->downloaderFactory
        );
        $this->downloadJob = new DownloadJob();
        $this->downloadJob->setUri('https://example.com/test.mp4');
        $this->downloadJob->setState(DownloadStateEnum::IN_PROGRESS);
        // The listener skips jobs without an identifier. Give the shared job a
        // persisted id so the existing tests exercise the normal database path.
        self::setJobId($this->downloadJob, 1);

        $this->process = $this->createMock(Process::class);
        $this->process->method('getCommandLine')->willReturn('yt-dlp https://example.com');
        $this->process->method('getOutput')->willReturn('Downloaded successfully');
        $this->process->method('getErrorOutput')->willReturn('');
    }

    private static function setJobId(DownloadJob $job, ?int $id): void
    {
        $property = new \ReflectionProperty(DownloadJob::class, 'id');
        $property->setValue($job, $id);
    }

    public function testOnCliProcessStopEventWithUnpersistedJobDoesNotQueryRepository(): void
    {
        // A DownloadJob built by app:url:download is never persisted, so its id is null.
        // Doctrine's find(null) throws MissingIdentifierField; the listener must skip it.
        self::setJobId($this->downloadJob, null);
        $event = new CliProcessStopEvent(
            $this->downloadJob,
            true,
            $this->process,
            0,
            'Success'
        );

        $this->assertNull($this->downloadJob->getId());
        $this->repository->expects($this->never())
            ->method('find');
        $this->entityManager->expects($this->never())
            ->method('persist');
        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->listener->onCliProcessStopEvent($event);
    }

    public function testOnCliProcessStopEventWithSuccessfulExit(): void
    {
        $event = new CliProcessStopEvent(
            $this->downloadJob,
            true,
            $this->process,
            0,
            'Success'
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturn($this->downloadJob);
        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');
        $this->logger->expects($this->once())
            ->method('info')
            ->with('CLI process stopped', $this->callback(function ($context) {
                return 0 === $context['exit_code'] && true === $context['is_successful'];
            }));

        $this->listener->onCliProcessStopEvent($event);
        $this->assertSame(DownloadStateEnum::COMPLETED, $this->downloadJob->getState());
    }

    public function testOnCliProcessStopEventWithFailureExit(): void
    {
        $this->process->method('getErrorOutput')->willReturn('Connection refused');

        $event = new CliProcessStopEvent(
            $this->downloadJob,
            false,
            $this->process,
            1,
            'General error',
            'Connection refused'
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturn($this->downloadJob);
        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');
        $this->logger->expects($this->once())
            ->method('info');

        $this->listener->onCliProcessStopEvent($event);
        $this->assertSame(DownloadStateEnum::FAILED, $this->downloadJob->getState());
    }

    public function testOnCliProcessStopEventLogsCorrectInformation(): void
    {
        $event = new CliProcessStopEvent(
            $this->downloadJob,
            true,
            $this->process,
            0,
            'Success'
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturn($this->downloadJob);
        $this->entityManager->expects($this->exactly(2))
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onCliProcessStopEvent($event);
    }

    public function testOnCliProcessStopEventWhenJobNotFound(): void
    {
        $event = new CliProcessStopEvent(
            $this->downloadJob,
            true,
            $this->process,
            0,
            'Success'
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturn(null);
        $this->entityManager->expects($this->never())
            ->method('persist');
        $this->entityManager->expects($this->never())
            ->method('flush');
        $this->logger->expects($this->once())
            ->method('info');

        $this->listener->onCliProcessStopEvent($event);
    }

    public function testAddFilesToDownloadJobFromCommandOutputWhenJobNotCompleted(): void
    {
        $this->downloadJob->setState(DownloadStateEnum::FAILED);

        $event = new CliProcessStopEvent(
            $this->downloadJob,
            false,
            $this->process,
            1,
            'Error'
        );

        $this->downloaderFactory->expects($this->never())
            ->method('getDownloadersByDownloadJob');

        $this->listener->addFilesToDownloadJobFromCommandOutput($event);
    }

    public function testAddFilesToDownloadJobFromCommandOutputWithValidDownloader(): void
    {
        $this->downloadJob->setState(DownloadStateEnum::COMPLETED);
        $this->downloadJob->setDownloader('youtube-dl');

        $event = new CliProcessStopEvent(
            $this->downloadJob,
            true,
            $this->process,
            0,
            'Success'
        );

        $downloader = $this->createMock(\App\Service\Downloader\AbstractCliDownloader::class);
        $downloader->expects($this->once())
            ->method('addFilesToDownloadJobFromCommandOutput')
            ->with($this->downloadJob, 'Downloaded successfully');

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('youtube-dl')
            ->willReturn($downloader);

        $this->listener->addFilesToDownloadJobFromCommandOutput($event);
    }
}
