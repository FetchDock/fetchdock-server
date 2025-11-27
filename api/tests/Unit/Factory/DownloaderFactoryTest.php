<?php

namespace App\Tests\Unit\Factory;

use App\Factory\DownloaderFactory;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\DownloaderInterface;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DownloaderFactoryTest extends TestCase
{
    private DownloaderFactory $factory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testConstructorIndexesDownloadersByIdentifier(): void
    {
        $downloader1 = $this->createMockDownloader('mock1', ['example.com']);
        $downloader2 = $this->createMockDownloader('mock2', ['test.com']);

        $this->factory = new DownloaderFactory([$downloader1, $downloader2], $this->logger);

        $enabledDownloaders = iterator_to_array($this->factory->getEnabledDownloaders());
        $this->assertCount(2, $enabledDownloaders);
        $this->assertArrayHasKey('mock1', $enabledDownloaders);
        $this->assertArrayHasKey('mock2', $enabledDownloaders);
        $this->assertSame($downloader1, $enabledDownloaders['mock1']);
        $this->assertSame($downloader2, $enabledDownloaders['mock2']);
    }

    public function testGetDownloaderByIdentifier(): void
    {
        $downloader = $this->createMockDownloader('test-downloader', ['example.com']);
        $this->factory = new DownloaderFactory([$downloader], $this->logger);

        $this->assertSame($downloader, $this->factory->getDownloaderByIdentifier('test-downloader'));
        $this->assertNull($this->factory->getDownloaderByIdentifier('non-existent'));
    }

    public function testIsValidDownloader(): void
    {
        $downloader = $this->createMockDownloader('valid-downloader', ['example.com']);
        $this->factory = new DownloaderFactory([$downloader], $this->logger);

        $this->assertTrue($this->factory->isValidDownloader('valid-downloader'));
        $this->assertFalse($this->factory->isValidDownloader('invalid-downloader'));
    }

    public function testGetDownloadersByUriWithSupportedDomain(): void
    {
        $downloader1 = $this->createMockDownloader('youtube-dl', ['youtube.com', 'youtu.be']);
        $downloader2 = $this->createMockDownloader('gallery-dl', ['instagram.com', 'twitter.com']);

        $downloader1->method('supportsUri')
            ->willReturnCallback(fn($uri) => in_array($uri->getHost(), ['youtube.com', 'youtu.be']));

        $downloader2->method('supportsUri')
            ->willReturnCallback(fn($uri) => in_array($uri->getHost(), ['instagram.com', 'twitter.com']));

        $this->factory = new DownloaderFactory([$downloader1, $downloader2], $this->logger);

        $youtubeUri = new Uri('https://youtube.com/watch?v=test');
        $supportedDownloaders = iterator_to_array($this->factory->getDownloadersByUri($youtubeUri));

        $this->assertCount(1, $supportedDownloaders);
        $this->assertSame($downloader1, $supportedDownloaders[0]);
    }

    public function testGetDownloadersByUriWithMultipleSupportedDownloaders(): void
    {
        $downloader1 = $this->createMockDownloader('universal1', ['example.com']);
        $downloader2 = $this->createMockDownloader('universal2', ['example.com']);

        $downloader1->method('supportsUri')
            ->willReturnCallback(fn($uri) => $uri->getHost() === 'example.com');

        $downloader2->method('supportsUri')
            ->willReturnCallback(fn($uri) => $uri->getHost() === 'example.com');

        $this->factory = new DownloaderFactory([$downloader1, $downloader2], $this->logger);

        $uri = new Uri('https://example.com/file.zip');
        $supportedDownloaders = iterator_to_array($this->factory->getDownloadersByUri($uri));

        $this->assertCount(2, $supportedDownloaders);
        $this->assertContains($downloader1, $supportedDownloaders);
        $this->assertContains($downloader2, $supportedDownloaders);
    }

    public function testGetDownloadersByUriWithNoSupportedDownloaders(): void
    {
        $downloader = $this->createMockDownloader('specific', ['youtube.com']);
        $downloader->method('supportsUri')
            ->willReturn(false);

        $this->factory = new DownloaderFactory([$downloader], $this->logger);

        $uri = new Uri('https://unsupported.com/file.zip');
        $supportedDownloaders = iterator_to_array($this->factory->getDownloadersByUri($uri));

        $this->assertCount(0, $supportedDownloaders);
    }

    public function testGetDownloadersByUriLogsDebugMessages(): void
    {
        $downloader = $this->createMockDownloader('test', ['example.com']);
        $downloader->method('supportsUri')->willReturn(true);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->logicalOr(
                $this->equalTo('Looking for downloaders supporting URI'),
                $this->equalTo('Checking downloader for URI support')
            ));

        $this->factory = new DownloaderFactory([$downloader], $this->logger);

        $uri = new Uri('https://example.com/test.zip');
        iterator_to_array($this->factory->getDownloadersByUri($uri));
    }

    public function testConstructorHandlesDuplicateIdentifiers(): void
    {
        // Test behavior when multiple downloaders have the same identifier
        $downloader1 = $this->createMockDownloader('duplicate', ['example1.com']);
        $downloader2 = $this->createMockDownloader('duplicate', ['example2.com']);

        $this->factory = new DownloaderFactory([$downloader1, $downloader2], $this->logger);

        // The second downloader should overwrite the first
        $enabledDownloaders = iterator_to_array($this->factory->getEnabledDownloaders());
        $this->assertCount(1, $enabledDownloaders);
        $this->assertArrayHasKey('duplicate', $enabledDownloaders);
        $this->assertSame($downloader2, $enabledDownloaders['duplicate']);
    }

    public function testGetEnabledDownloadersReturnsIterable(): void
    {
        $downloader = $this->createMockDownloader('test', ['example.com']);
        $this->factory = new DownloaderFactory([$downloader], $this->logger);

        $enabledDownloaders = $this->factory->getEnabledDownloaders();

        $this->assertIsIterable($enabledDownloaders);
        $this->assertContains($downloader, $enabledDownloaders);
    }

    public function testGetCliDownloadersReturnsOnlyCliDownloaders(): void
    {
        $cliDownloader1 = $this->createMockCliDownloader('cli-downloader-1', ['youtube.com']);
        $cliDownloader2 = $this->createMockCliDownloader('cli-downloader-2', ['twitter.com']);
        $nonCliDownloader = $this->createMockDownloader('non-cli-downloader', ['example.com']);

        $this->factory = new DownloaderFactory(
            [$cliDownloader1, $nonCliDownloader, $cliDownloader2],
            $this->logger
        );

        $cliDownloaders = iterator_to_array($this->factory->getCliDownloaders());

        $this->assertCount(2, $cliDownloaders);
        $this->assertContains($cliDownloader1, $cliDownloaders);
        $this->assertContains($cliDownloader2, $cliDownloaders);
        $this->assertNotContains($nonCliDownloader, $cliDownloaders);
    }

    public function testGetCliDownloadersFiltersOutNonCliDownloaders(): void
    {
        $nonCliDownloader1 = $this->createMockDownloader('non-cli-1', ['example.com']);
        $nonCliDownloader2 = $this->createMockDownloader('non-cli-2', ['test.com']);
        $cliDownloader = $this->createMockCliDownloader('cli-downloader', ['youtube.com']);

        $this->factory = new DownloaderFactory(
            [$nonCliDownloader1, $cliDownloader, $nonCliDownloader2],
            $this->logger
        );

        $cliDownloaders = iterator_to_array($this->factory->getCliDownloaders());

        $this->assertCount(1, $cliDownloaders);
        $this->assertContains($cliDownloader, $cliDownloaders);
        foreach ($cliDownloaders as $downloader) {
            $this->assertInstanceOf(CliDownloaderInterface::class, $downloader);
        }
    }

    public function testGetCliDownloadersReturnsIterableThatCanBeConvertedToArray(): void
    {
        $cliDownloader = $this->createMockCliDownloader('cli-downloader', ['youtube.com']);
        $this->factory = new DownloaderFactory([$cliDownloader], $this->logger);

        $cliDownloaders = $this->factory->getCliDownloaders();

        $this->assertIsIterable($cliDownloaders);
        $cliDownloadersArray = iterator_to_array($cliDownloaders);
        $this->assertIsArray($cliDownloadersArray);
        $this->assertCount(1, $cliDownloadersArray);
        $this->assertSame($cliDownloader, $cliDownloadersArray[0]);
    }

    public function testGetCliDownloadersReturnsEmptyIterableWhenNoCliDownloaders(): void
    {
        $nonCliDownloader = $this->createMockDownloader('non-cli', ['example.com']);
        $this->factory = new DownloaderFactory([$nonCliDownloader], $this->logger);

        $cliDownloaders = iterator_to_array($this->factory->getCliDownloaders());

        $this->assertCount(0, $cliDownloaders);
    }

    private function createMockDownloader(string $identifier, array $supportedDomains): DownloaderInterface
    {
        $mock = $this->createMock(DownloaderInterface::class);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getSupportedDomains')->willReturn($supportedDomains);

        return $mock;
    }

    private function createMockCliDownloader(string $identifier, array $supportedDomains): CliDownloaderInterface
    {
        $mock = $this->createMock(CliDownloaderInterface::class);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getSupportedDomains')->willReturn($supportedDomains);

        return $mock;
    }
}
