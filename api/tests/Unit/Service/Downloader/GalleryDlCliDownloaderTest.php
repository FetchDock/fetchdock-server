<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\GalleryDlCliDownloader;
use App\Service\Storage\DownloadLocation;
use App\Service\Storage\DownloadLocationResolver;
use App\Service\Storage\Paths;
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

    public function testAddFilesReadsPathsRelativeToTheJobsDirectory(): void
    {
        $target = sys_get_temp_dir().'/gallery-dl-test-'.bin2hex(random_bytes(4));
        mkdir($target.'/gallery-dl/site', 0o700, true);
        touch($target.'/gallery-dl/site/a.jpg');
        touch($target.'/gallery-dl/site/b.jpg');
        $directory = new DownloadDirectory('Site', $target);

        $resolver = $this->createStub(DownloadLocationResolver::class);
        $resolver->method('fromAbsolute')->willReturnCallback(static fn (string $path) => new DownloadLocation($directory, (string) Paths::relativeTo($path, $target)));
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof DownloadedFile) {
                $persisted[] = $entity->getPath();
            }
        });
        $downloader = new GalleryDlCliDownloader($this->cache, '/tmp/test-config.json', '/usr/bin/gallery-dl', '/tmp/downloads', $this->logger, $this->eventDispatcher, $this->downloadedFileRepository, $this->entityManager, $resolver);

        try {
            $downloader->addFilesToDownloadJobFromCommandOutput(
                new DownloadJob()->setUri('https://example.com/gallery')->setDownloadDirectory($directory),
                "./gallery-dl/site/a.jpg\n# {$target}/gallery-dl/site/b.jpg\n",
            );
        } finally {
            exec('rm -rf '.escapeshellarg($target));
        }

        $this->assertSame([$target.'/gallery-dl/site/a.jpg', $target.'/gallery-dl/site/b.jpg'], $persisted);
    }
}
