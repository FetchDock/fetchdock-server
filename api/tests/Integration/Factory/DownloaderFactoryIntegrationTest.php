<?php

namespace App\Tests\Integration\Factory;

use App\Factory\DownloaderFactory;
use App\Service\Downloader\MockDownloader;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration test for DownloaderFactory with real downloader implementations
 */
class DownloaderFactoryIntegrationTest extends TestCase
{
    private DownloaderFactory $factory;
    private LoggerInterface $logger;
    private MockDownloader $mockDownloader;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mockDownloader = new MockDownloader();

        $this->factory = new DownloaderFactory(
            [$this->mockDownloader],
            $this->logger
        );
    }

    public function testFactoryWithRealMockDownloader(): void
    {
        $enabledDownloaders = iterator_to_array($this->factory->getEnabledDownloaders());

        $this->assertCount(1, $enabledDownloaders);
        $this->assertArrayHasKey('mock', $enabledDownloaders);
        $this->assertSame($this->mockDownloader, $enabledDownloaders['mock']);
    }

    public function testGetDownloaderByIdentifierWithRealDownloader(): void
    {
        $downloader = $this->factory->getDownloaderByIdentifier('mock');

        $this->assertSame($this->mockDownloader, $downloader);
        $this->assertSame('mock', $downloader->getIdentifier());
        $this->assertSame(['example.com', 'test.com'], $downloader->getSupportedDomains());
    }

    public function testGetDownloadersByUriWithRealDownloaderSupport(): void
    {
        $supportedUri = new Uri('https://example.com/video.mp4');
        $supportedDownloaders = iterator_to_array($this->factory->getDownloadersByUri($supportedUri));

        $this->assertCount(1, $supportedDownloaders);
        $this->assertSame($this->mockDownloader, $supportedDownloaders[0]);
    }

    public function testGetDownloadersByUriWithUnsupportedDomain(): void
    {
        $unsupportedUri = new Uri('https://notsupported.com/video.mp4');
        $supportedDownloaders = iterator_to_array($this->factory->getDownloadersByUri($unsupportedUri));

        $this->assertCount(0, $supportedDownloaders);
    }

    public function testRealDownloaderProperties(): void
    {
        $downloader = $this->factory->getDownloaderByIdentifier('mock');

        $this->assertSame('1.0.0-mock', $downloader->getCurrentVersion());
        $this->assertSame(\App\Enum\DownloaderTypeEnum::CLI_DOWNLOADER, $downloader->getDownloaderType());
    }

    public function testFactoryWithMultipleRealDownloaders(): void
    {
        // Create a second mock downloader with different configuration
        $secondMockDownloader = new class extends MockDownloader {
            public function getIdentifier(): string
            {
                return 'mock-2';
            }

            public function getSupportedDomains(): array
            {
                return ['domain2.com', 'site2.org'];
            }
        };

        $factory = new DownloaderFactory(
            [$this->mockDownloader, $secondMockDownloader],
            $this->logger
        );

        $enabledDownloaders = iterator_to_array($factory->getEnabledDownloaders());
        $this->assertCount(2, $enabledDownloaders);
        $this->assertArrayHasKey('mock', $enabledDownloaders);
        $this->assertArrayHasKey('mock-2', $enabledDownloaders);

        // Test URI resolution with different downloaders
        $uri1 = new Uri('https://example.com/test.zip');
        $downloaders1 = iterator_to_array($factory->getDownloadersByUri($uri1));
        $this->assertCount(1, $downloaders1);
        $this->assertSame('mock', $downloaders1[0]->getIdentifier());

        $uri2 = new Uri('https://domain2.com/test.zip');
        $downloaders2 = iterator_to_array($factory->getDownloadersByUri($uri2));
        $this->assertCount(1, $downloaders2);
        $this->assertSame('mock-2', $downloaders2[0]->getIdentifier());
    }

    public function testLoggingIntegrationWithRealDownloader(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $matcher = $this->exactly(2);
        $logger->expects($matcher)
            ->method('debug')
            ->willReturnCallback(function(string $key, array $value) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertSame('Looking for downloaders supporting URI', $key),
                    2 => $this->assertSame('Checking downloader for URI support', $key),
                    default => throw new \LogicException('Unexpected number of logger calls')
                };
            });

        $factory = new DownloaderFactory([$this->mockDownloader], $logger);

        $uri = new Uri('https://example.com/test.zip');
        iterator_to_array($factory->getDownloadersByUri($uri));
    }

    public function testValidDownloaderCheckWithRealDownloader(): void
    {
        $this->assertTrue($this->factory->isValidDownloader('mock'));
        $this->assertFalse($this->factory->isValidDownloader('nonexistent'));
        $this->assertFalse($this->factory->isValidDownloader(''));
    }

    public function testDownloaderFactoryConsistency(): void
    {
        // Test that repeated calls return consistent results
        $downloader1 = $this->factory->getDownloaderByIdentifier('mock');
        $downloader2 = $this->factory->getDownloaderByIdentifier('mock');

        $this->assertSame($downloader1, $downloader2);

        $uri = new Uri('https://example.com/consistency-test.zip');
        $downloaders1 = iterator_to_array($this->factory->getDownloadersByUri($uri));
        $downloaders2 = iterator_to_array($this->factory->getDownloadersByUri($uri));

        $this->assertCount(1, $downloaders1);
        $this->assertCount(1, $downloaders2);
        $this->assertSame($downloaders1[0], $downloaders2[0]);
    }

    public function testFactoryHandlesRealDownloaderExceptions(): void
    {
        // Create a downloader that throws exceptions during URI checking
        $problematicDownloader = new class extends MockDownloader {
            public function getIdentifier(): string
            {
                return 'problematic';
            }

            public function supportsUri(\Psr\Http\Message\UriInterface $uri): bool
            {
                throw new \RuntimeException('Downloader error');
            }
        };

        $factory = new DownloaderFactory(
            [$this->mockDownloader, $problematicDownloader],
            $this->logger
        );

        // The factory should handle the exception gracefully and continue with other downloaders
        $uri = new Uri('https://example.com/test.zip');

        $this->expectException(\RuntimeException::class);
        iterator_to_array($factory->getDownloadersByUri($uri));
    }
}
