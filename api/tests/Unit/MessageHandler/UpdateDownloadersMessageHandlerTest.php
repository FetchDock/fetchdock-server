<?php

namespace App\Tests\Unit\MessageHandler;

use App\Factory\DownloaderFactory;
use App\Message\UpdateDownloadersMessage;
use App\MessageHandler\UpdateDownloadersMessageHandler;
use App\Service\Downloader\CliDownloaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateDownloadersMessageHandlerTest extends TestCase
{
    private DownloaderFactory|\PHPUnit\Framework\MockObject\MockObject $downloaderFactory;
    private LoggerInterface|\PHPUnit\Framework\MockObject\MockObject $logger;
    private UpdateDownloadersMessageHandler $handler;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new UpdateDownloadersMessageHandler($this->downloaderFactory, $this->logger);
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
}
