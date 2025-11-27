<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\YoutubeDlCliDownloader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class YoutubeDlCliDownloaderTest extends TestCase
{
    private YoutubeDlCliDownloader $downloader;
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

        $this->downloader = new YoutubeDlCliDownloader(
            $this->cache,
            '/tmp/test-config.conf',
            '/usr/bin/yt-dlp',
            '/tmp/downloads',
            $this->logger,
            $this->eventDispatcher,
            $this->downloadedFileRepository,
            $this->entityManager
        );
    }

    public function testImplementsCliDownloaderInterface(): void
    {
        $this->assertInstanceOf(CliDownloaderInterface::class, $this->downloader);
    }

    public function testGetIdentifier(): void
    {
        $this->assertSame('yt-dlp-cli', $this->downloader->getIdentifier());
    }

    public function testGetUpdateCommandArgsReturnsCorrectArray(): void
    {
        $expectedArgs = ['pip', 'install', '--upgrade', 'yt-dlp'];

        $actualArgs = $this->downloader->getUpdateCommandArgs();

        $this->assertSame($expectedArgs, $actualArgs);
    }

    public function testGetUpdateCommandArgsReturnsNonEmptyArray(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertNotEmpty($args);
        $this->assertIsArray($args);
    }

    public function testGetUpdateCommandArgsContainsPipCommand(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertSame('pip', $args[0]);
    }

    public function testGetUpdateCommandArgsContainsInstallSubcommand(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertSame('install', $args[1]);
    }

    public function testGetUpdateCommandArgsContainsUpgradeFlag(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertSame('--upgrade', $args[2]);
    }

    public function testGetUpdateCommandArgsContainsCorrectPackageName(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertSame('yt-dlp', $args[3]);
    }

    public function testGetUpdateCommandArgsHasExactlyFourElements(): void
    {
        $args = $this->downloader->getUpdateCommandArgs();

        $this->assertCount(4, $args);
    }

    public function testGetSupportedDomainsReturnsEmptyArray(): void
    {
        // YoutubeDlCliDownloader uses --simulate to check support dynamically
        // so getSupportedDomains returns an empty array
        $this->assertSame([], $this->downloader->getSupportedDomains());
    }
}
