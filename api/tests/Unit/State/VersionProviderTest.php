<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Factory\DownloaderFactory;
use App\Resource\Version;
use App\Service\Downloader\DownloaderInterface;
use App\State\VersionProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VersionProviderTest extends TestCase
{
    private VersionProvider $provider;
    private DownloaderFactory $downloaderFactory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = new VersionProvider($this->downloaderFactory, $this->logger);
    }

    public function testProvideCollectionReturnsVersionsWithLatestVersion(): void
    {
        $mockDownloader1 = $this->createMock(DownloaderInterface::class);
        $mockDownloader1->method('getIdentifier')->willReturn('downloader1');
        $mockDownloader1->method('getCurrentVersion')->willReturn('1.0.0');
        $mockDownloader1->method('getLatestVersion')->willReturn('1.1.0');

        $mockDownloader2 = $this->createMock(DownloaderInterface::class);
        $mockDownloader2->method('getIdentifier')->willReturn('downloader2');
        $mockDownloader2->method('getCurrentVersion')->willReturn('2.0.0');
        $mockDownloader2->method('getLatestVersion')->willReturn('2.5.0');

        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$mockDownloader1, $mockDownloader2]);

        // Use real GetCollection instance - it extends Operation and implements CollectionOperationInterface
        $operation = new GetCollection();

        $result = $this->provider->provide($operation);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // Verify first version has correct latestVersion
        $this->assertInstanceOf(Version::class, $result[0]);
        $this->assertSame('downloader1', $result[0]->id);
        $this->assertSame('1.0.0', $result[0]->currentVersion);
        $this->assertSame('1.1.0', $result[0]->latestVersion);

        // Verify second version has correct latestVersion
        $this->assertInstanceOf(Version::class, $result[1]);
        $this->assertSame('downloader2', $result[1]->id);
        $this->assertSame('2.0.0', $result[1]->currentVersion);
        $this->assertSame('2.5.0', $result[1]->latestVersion);
    }

    public function testProvideSingleVersionReturnsVersionWithLatestVersion(): void
    {
        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('test-downloader');
        $mockDownloader->method('getCurrentVersion')->willReturn('3.0.0');
        $mockDownloader->method('getLatestVersion')->willReturn('3.2.0');

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('test-downloader')
            ->willReturn($mockDownloader);

        // Use real Get instance - it extends Operation
        $operation = new Get();

        $result = $this->provider->provide($operation, ['id' => 'test-downloader']);

        $this->assertInstanceOf(Version::class, $result);
        $this->assertSame('test-downloader', $result->id);
        $this->assertSame('3.0.0', $result->currentVersion);
        $this->assertSame('3.2.0', $result->latestVersion);
    }

    public function testProvideSingleVersionReturnsNullForUnknownDownloader(): void
    {
        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('unknown-downloader')
            ->willReturn(null);

        // Use real Get instance - it extends Operation
        $operation = new Get();

        $result = $this->provider->provide($operation, ['id' => 'unknown-downloader']);

        $this->assertNull($result);
    }

    public function testProvideWithoutIdReturnsNull(): void
    {
        // Use real Get instance - it extends Operation
        $operation = new Get();

        $result = $this->provider->provide($operation);

        $this->assertNull($result);
    }

    public function testProvideCollectionWithNoDownloadersReturnsEmptyArray(): void
    {
        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([]);

        // Use real GetCollection instance - it extends Operation and implements CollectionOperationInterface
        $operation = new GetCollection();

        $result = $this->provider->provide($operation);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testVersionPropertyMatchesBothCurrentAndLatestWhenUpToDate(): void
    {
        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('up-to-date');
        $mockDownloader->method('getCurrentVersion')->willReturn('5.0.0');
        $mockDownloader->method('getLatestVersion')->willReturn('5.0.0');

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('up-to-date')
            ->willReturn($mockDownloader);

        // Use real Get instance - it extends Operation
        $operation = new Get();

        $result = $this->provider->provide($operation, ['id' => 'up-to-date']);

        $this->assertInstanceOf(Version::class, $result);
        $this->assertSame('5.0.0', $result->version);
        $this->assertSame('5.0.0', $result->currentVersion);
        $this->assertSame('5.0.0', $result->latestVersion);
        // Verify version equals currentVersion (deprecated field)
        $this->assertSame($result->currentVersion, $result->version);
    }

    public function testProvideCollectionContinuesOnErrorAndLogsFailure(): void
    {
        $mockDownloader1 = $this->createMock(DownloaderInterface::class);
        $mockDownloader1->method('getIdentifier')->willReturn('failing-downloader');
        $mockDownloader1->method('getCurrentVersion')->willThrowException(new \RuntimeException('Version fetch failed'));

        $mockDownloader2 = $this->createMock(DownloaderInterface::class);
        $mockDownloader2->method('getIdentifier')->willReturn('working-downloader');
        $mockDownloader2->method('getCurrentVersion')->willReturn('2.0.0');
        $mockDownloader2->method('getLatestVersion')->willReturn('2.5.0');

        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$mockDownloader1, $mockDownloader2]);

        // Expect error to be logged for the failing downloader
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get version info', $this->callback(function (array $context) {
                return 'failing-downloader' === $context['downloader']
                    && 'Version fetch failed' === $context['error'];
            }));

        $operation = new GetCollection();

        $result = $this->provider->provide($operation);

        // Should still return array with successful downloader
        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        // Verify the working downloader is in the result
        $this->assertInstanceOf(Version::class, $result[0]);
        $this->assertSame('working-downloader', $result[0]->id);
        $this->assertSame('2.0.0', $result[0]->currentVersion);
        $this->assertSame('2.5.0', $result[0]->latestVersion);
    }

    public function testProvideCollectionHandlesLatestVersionError(): void
    {
        $mockDownloader1 = $this->createMock(DownloaderInterface::class);
        $mockDownloader1->method('getIdentifier')->willReturn('downloader-with-latest-error');
        $mockDownloader1->method('getCurrentVersion')->willReturn('1.0.0');
        $mockDownloader1->method('getLatestVersion')->willThrowException(new \RuntimeException('Latest version fetch failed'));

        $mockDownloader2 = $this->createMock(DownloaderInterface::class);
        $mockDownloader2->method('getIdentifier')->willReturn('healthy-downloader');
        $mockDownloader2->method('getCurrentVersion')->willReturn('3.0.0');
        $mockDownloader2->method('getLatestVersion')->willReturn('3.1.0');

        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$mockDownloader1, $mockDownloader2]);

        // Expect error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get version info', $this->callback(function (array $context) {
                return 'downloader-with-latest-error' === $context['downloader']
                    && 'Latest version fetch failed' === $context['error'];
            }));

        $operation = new GetCollection();

        $result = $this->provider->provide($operation);

        // Should still return array with healthy downloader
        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $this->assertInstanceOf(Version::class, $result[0]);
        $this->assertSame('healthy-downloader', $result[0]->id);
    }

    public function testProvideSingleVersionReturnsNullOnErrorAndLogsFailure(): void
    {
        $mockDownloader = $this->createMock(DownloaderInterface::class);
        $mockDownloader->method('getIdentifier')->willReturn('failing-single');
        $mockDownloader->method('getCurrentVersion')->willThrowException(new \RuntimeException('Single version fetch failed'));

        $this->downloaderFactory->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('failing-single')
            ->willReturn($mockDownloader);

        // Expect error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get version info', $this->callback(function (array $context) {
                return 'failing-single' === $context['downloader']
                    && 'Single version fetch failed' === $context['error'];
            }));

        $operation = new Get();

        $result = $this->provider->provide($operation, ['id' => 'failing-single']);

        // Should return null on error
        $this->assertNull($result);
    }
}
