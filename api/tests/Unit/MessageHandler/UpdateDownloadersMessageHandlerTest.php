<?php

namespace App\Tests\Unit\MessageHandler;

use App\Factory\DownloaderFactory;
use App\Message\UpdateDownloadersMessage;
use App\MessageHandler\UpdateDownloadersMessageHandler;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Site\SiteSynchronizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateDownloadersMessageHandlerTest extends TestCase
{
    private DownloaderFactory|\PHPUnit\Framework\MockObject\MockObject $downloaderFactory;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;
    private SiteSynchronizer|\PHPUnit\Framework\MockObject\MockObject $siteSynchronizer;
    private UpdateDownloadersMessageHandler $handler;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->siteSynchronizer = $this->createMock(SiteSynchronizer::class);
        $this->handler = new UpdateDownloadersMessageHandler($this->downloaderFactory, $this->logger, $this->siteSynchronizer);
    }

    public function testHandlerIsInstantiable(): void
    {
        $this->assertInstanceOf(UpdateDownloadersMessageHandler::class, $this->handler);
    }

    public function testHandlerWithNoEnabledDownloaders(): void
    {
        $message = new UpdateDownloadersMessage();

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([]);

        $this->handler->__invoke($message);
    }

    public function testHandlerWithNonCliDownloader(): void
    {
        $message = new UpdateDownloadersMessage();
        $downloader = $this->createMock(\App\Service\Downloader\DownloaderInterface::class);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$downloader]);

        // Should not attempt to run any process
        $this->handler->__invoke($message);
    }

    public function testHandlerWithCliDownloader(): void
    {
        $message = new UpdateDownloadersMessage();
        $downloader = $this->createMock(CliDownloaderInterface::class);

        $downloader
            ->expects($this->once())
            ->method('getUpdateCommandArgs')
            ->willReturn(['echo', 'updating']);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$downloader]);

        // This will attempt to run a process, which should succeed with echo
        $this->handler->__invoke($message);
    }

    public function testHandlerIsCallable(): void
    {
        $this->assertTrue(is_callable($this->handler));
    }

    public function testHandlerSynchronisesSitesAfterUpdating(): void
    {
        $this->downloaderFactory->expects($this->once())->method('getEnabledDownloaders')->willReturn([]);
        $this->siteSynchronizer->expects($this->once())->method('sync');

        $this->handler->__invoke(new UpdateDownloadersMessage());
    }

    public function testHandlerLogsSiteSynchronisationFailure(): void
    {
        $this->downloaderFactory->expects($this->once())->method('getEnabledDownloaders')->willReturn([]);
        $this->siteSynchronizer->method('sync')->willThrowException(new \RuntimeException('helper failed'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Site synchronisation'));

        $this->handler->__invoke(new UpdateDownloadersMessage());
    }
}
