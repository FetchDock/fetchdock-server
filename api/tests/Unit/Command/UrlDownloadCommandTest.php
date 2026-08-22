<?php

namespace App\Tests\Unit\Command;

use App\Command\UrlDownloadCommand;
use App\Entity\DownloadJob;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use GuzzleHttp\Psr7\Uri;
use \RuntimeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

class UrlDownloadCommandTest extends TestCase
{
    private DownloaderFactory|\PHPUnit\Framework\MockObject\MockObject $downloaderFactory;
    private DownloaderInterface|\PHPUnit\Framework\MockObject\MockObject $downloader;
    private UrlDownloadCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->downloader = $this->createMock(DownloaderInterface::class);
        $this->command = new UrlDownloadCommand($this->downloaderFactory);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteWithValidUrlAndAutoDetectedDownloader(): void
    {
        $url = 'https://example.com/video.mp4';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([$this->downloader]);

        $this->downloader
            ->expects($this->once())
            ->method('download')
            ->willReturn(true);

        $statusCode = $this->commandTester->execute(['url' => $url]);

        $this->assertEquals(0, $statusCode);
        $this->assertStringContainsString('Downloading URL:', $this->commandTester->getDisplay());
        $this->assertStringContainsString('URL sent to download server successfully!', $this->commandTester->getDisplay());
    }

    public function testExecuteWithValidUrlAndSpecificDownloader(): void
    {
        $url = 'https://example.com/video.mp4';
        $downloaderIdentifier = 'youtube-dl';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with($downloaderIdentifier)
            ->willReturn($this->downloader);

        $this->downloader
            ->expects($this->once())
            ->method('supportsDownloadJob')
            ->willReturn(true);

        $this->downloader
            ->expects($this->once())
            ->method('download')
            ->willReturn(true);

        $statusCode = $this->commandTester->execute([
            'url' => $url,
            '--downloader' => $downloaderIdentifier,
        ]);

        $this->assertEquals(0, $statusCode);
        $this->assertStringContainsString('URL sent to download server successfully!', $this->commandTester->getDisplay());
    }

    public function testExecuteWithDownloaderNotFound(): void
    {
        $url = 'https://example.com/video.mp4';
        $downloaderIdentifier = 'nonexistent-downloader';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with($downloaderIdentifier)
            ->willReturn(null);

        $statusCode = $this->commandTester->execute([
            'url' => $url,
            '--downloader' => $downloaderIdentifier,
        ]);

        $this->assertEquals(1, $statusCode);
        $this->assertStringContainsString('Downloader with identifier', $this->commandTester->getDisplay());
        $this->assertStringContainsString('not found!', $this->commandTester->getDisplay());
    }

    public function testExecuteWithDownloaderDoesNotSupportUrl(): void
    {
        $url = 'https://example.com/unsupported.mp4';
        $downloaderIdentifier = 'youtube-dl';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with($downloaderIdentifier)
            ->willReturn($this->downloader);

        $this->downloader
            ->expects($this->once())
            ->method('supportsDownloadJob')
            ->willReturn(false);

        $statusCode = $this->commandTester->execute([
            'url' => $url,
            '--downloader' => $downloaderIdentifier,
        ]);

        $this->assertEquals(1, $statusCode);
        $this->assertStringContainsString('does not support the given URL!', $this->commandTester->getDisplay());
    }

    public function testExecuteWithDownloadFailure(): void
    {
        self::markTestIncomplete();
        $url = 'https://example.com/video.mp4';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([$this->downloader]);

        $this->downloader
            ->expects($this->once())
            ->method('download')
            ->willThrowException(new RuntimeException());

        $statusCode = $this->commandTester->execute(['url' => $url]);

        $this->assertEquals(1, $statusCode);
        $this->assertStringContainsString('Failed to send URL to download server!', $this->commandTester->getDisplay());
    }

    public function testExecuteWithNoAvailableDownloaders(): void
    {
        $url = 'https://example.com/video.mp4';

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloadersByDownloadJob')
            ->willReturn([]);

        $statusCode = $this->commandTester->execute(['url' => $url]);

        // When no downloader is found, the command still runs but $this->downloader remains null
        // This will cause an error when trying to call download() on null
        // The command expects at least one downloader to be available
        $this->assertEquals(1, $statusCode);
    }

    public function testCommandDescription(): void
    {
        $this->assertStringContainsString('Send a URL to the download server', $this->command->getDescription());
    }

    public function testCommandName(): void
    {
        $this->assertEquals('app:url:download', $this->command->getName());
    }
}
