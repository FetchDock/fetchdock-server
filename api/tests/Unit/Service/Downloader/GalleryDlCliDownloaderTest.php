<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\GalleryDlCliDownloader;
use App\Service\Storage\DownloadLocationResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GalleryDlCliDownloaderTest extends TestCase
{
    private GalleryDlCliDownloader $downloader;
    private TagAwareCacheInterface $cache;
    private LoggerInterface $logger;
    private EventDispatcherInterface $eventDispatcher;
    private DownloadedFileRepository $downloadedFileRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->downloadedFileRepository = $this->createMock(DownloadedFileRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->downloader = new GalleryDlCliDownloader(
            $this->cache,
            '/tmp/test-config.json',
            '/usr/bin/gallery-dl',
            '/tmp/downloads',
            $this->logger,
            $this->eventDispatcher,
            $this->downloadedFileRepository,
            $this->entityManager,
            $this->createStub(DownloadLocationResolver::class),
        );
    }

    public function testImplementsCliDownloaderInterface(): void
    {
        $this->assertInstanceOf(CliDownloaderInterface::class, $this->downloader);
    }

    public function testGetIdentifier(): void
    {
        $this->assertSame('gallery-dl-cli', $this->downloader->getIdentifier());
    }

    public function testGetUpdateCommandArgsReturnsCorrectArray(): void
    {
        $expectedArgs = ['pip3', 'install', '--upgrade', '--break-system-packages', 'gallery-dl'];

        $actualArgs = $this->downloader->getUpdateCommandArgs();

        $this->assertSame($expectedArgs, $actualArgs);
        $this->assertCount(5, $actualArgs);
    }

    public function testCommandOptionsIncludeUserAgentAndReferrer(): void
    {
        $job = new DownloadJob()->setUri('https://example.com/media')->setUserAgent('UA/1.0')->setReferrer('https://example.com/page');

        $this->assertSame(['--user-agent', 'UA/1.0', '--option', 'referer=https://example.com/page'], new \ReflectionMethod($this->downloader, 'getCommandOptions')->invoke($this->downloader, $job));
    }

    public function testCommandOptionsOmitMissingReferrer(): void
    {
        $job = new DownloadJob()->setUri('https://example.com/media');

        $this->assertSame([], new \ReflectionMethod($this->downloader, 'getCommandOptions')->invoke($this->downloader, $job));
    }
}
