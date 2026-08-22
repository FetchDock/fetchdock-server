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

        $this->process = $this->createMock(Process::class);
        $this->process->method('getCommandLine')->willReturn('yt-dlp https://example.com');
        $this->process->method('getOutput')->willReturn('Downloaded successfully');
        $this->process->method('getErrorOutput')->willReturn('');
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
                return $context['exit_code'] === 0 && $context['is_successful'] === true;
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
