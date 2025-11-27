<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use App\Entity\Version;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use App\State\VersionProvider;
use PHPUnit\Framework\TestCase;

class VersionProviderTest extends TestCase
{
    private VersionProvider $provider;
    private DownloaderFactory $downloaderFactory;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->provider = new VersionProvider($this->downloaderFactory);
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

        $operation = $this->createMock(GetCollection::class);

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

        $operation = $this->createMock(Get::class);

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

        $operation = $this->createMock(Get::class);

        $result = $this->provider->provide($operation, ['id' => 'unknown-downloader']);

        $this->assertNull($result);
    }

    public function testProvideWithoutIdReturnsNull(): void
    {
        $operation = $this->createMock(Get::class);

        $result = $this->provider->provide($operation);

        $this->assertNull($result);
    }

    public function testProvideCollectionWithNoDownloadersReturnsEmptyArray(): void
    {
        $this->downloaderFactory->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([]);

        $operation = $this->createMock(GetCollection::class);

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

        $operation = $this->createMock(Get::class);

        $result = $this->provider->provide($operation, ['id' => 'up-to-date']);

        $this->assertInstanceOf(Version::class, $result);
        $this->assertSame('5.0.0', $result->version);
        $this->assertSame('5.0.0', $result->currentVersion);
        $this->assertSame('5.0.0', $result->latestVersion);
        // Verify version equals currentVersion (deprecated field)
        $this->assertSame($result->currentVersion, $result->version);
    }
}
