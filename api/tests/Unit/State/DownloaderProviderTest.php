<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Get;
use App\Enum\DownloaderTypeEnum;
use App\Factory\DownloaderFactory;
use App\Resource\Downloader;
use App\Service\Downloader\DownloaderInterface;
use App\State\DownloaderProvider;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class DownloaderProviderTest extends TestCase
{
    private DownloaderFactory|\PHPUnit\Framework\MockObject\MockObject $downloaderFactory;
    private DownloaderProvider $provider;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->provider = new DownloaderProvider($this->downloaderFactory);
    }

    public function testProviderIsInstantiable(): void
    {
        $this->assertInstanceOf(DownloaderProvider::class, $this->provider);
    }

    public function testProvideCollectionOperation(): void
    {
        $operation = new \ApiPlatform\Metadata\GetCollection();
        $downloader = $this->createMock(DownloaderInterface::class);

        $downloader->expects($this->once())->method('getIdentifier')->willReturn('youtube-dl');
        $downloader->expects($this->once())->method('getDownloaderType')->willReturn(DownloaderTypeEnum::CLI_DOWNLOADER);
        $downloader->expects($this->once())->method('getSupportedDomains')->willReturn(['youtube.com', 'youtu.be']);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$downloader]);

        $result = $this->provider->provide($operation);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Downloader::class, $result[0]);
        $this->assertEquals('youtube-dl', $result[0]->id);
    }

    public function testProvideSingleDownloaderWithValidId(): void
    {
        $operation = new Get();
        $downloader = $this->createMock(DownloaderInterface::class);

        $downloader->expects($this->once())->method('getIdentifier')->willReturn('gallery-dl');
        $downloader->expects($this->once())->method('getDownloaderType')->willReturn(DownloaderTypeEnum::CLI_DOWNLOADER);
        $downloader->expects($this->once())->method('getSupportedDomains')->willReturn(['pixiv.net', 'instagram.com']);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('gallery-dl')
            ->willReturn($downloader);

        $result = $this->provider->provide($operation, ['id' => 'gallery-dl']);

        $this->assertInstanceOf(Downloader::class, $result);
        $this->assertEquals('gallery-dl', $result->id);
    }

    public function testProvideSingleDownloaderWithInvalidId(): void
    {
        $operation = new Get();

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getDownloaderByIdentifier')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->provider->provide($operation, ['id' => 'nonexistent']);

        $this->assertNull($result);
    }

    public function testProvideSingleDownloaderWithoutId(): void
    {
        $operation = new Get();

        $result = $this->provider->provide($operation, []);

        $this->assertNull($result);
    }

    public function testProvideWithUrlDomains(): void
    {
        $operation = new \ApiPlatform\Metadata\GetCollection();
        $downloader = $this->createMock(DownloaderInterface::class);

        $downloader->expects($this->once())->method('getIdentifier')->willReturn('youtube-dl');
        $downloader->expects($this->once())->method('getDownloaderType')->willReturn(DownloaderTypeEnum::CLI_DOWNLOADER);
        $downloader->expects($this->once())->method('getSupportedDomains')->willReturn([
            'https://youtube.com',
            'http://youtu.be',
            'youtube.com',
        ]);

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([$downloader]);

        $result = $this->provider->provide($operation);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Downloader::class, $result[0]);
        // Should extract hostnames from URLs and remove duplicates
        $this->assertEquals(['youtube.com', 'youtu.be'], $result[0]->supportedDomains);
    }

    public function testProvideWithEmptyDownloadersList(): void
    {
        $operation = new \ApiPlatform\Metadata\GetCollection();

        $this->downloaderFactory
            ->expects($this->once())
            ->method('getEnabledDownloaders')
            ->willReturn([]);

        $result = $this->provider->provide($operation);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testProvideImplementsProviderInterface(): void
    {
        $this->assertInstanceOf(\ApiPlatform\State\ProviderInterface::class, $this->provider);
    }
}
