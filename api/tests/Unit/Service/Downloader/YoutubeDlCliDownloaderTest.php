<?php

namespace App\Tests\Unit\Service\Downloader;

use App\Entity\DownloadedFile;
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
    /** @var list<string> */
    private array $temporaryDirectories = [];

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
        $expectedArgs = ['pip3', 'install', '--upgrade', '--break-system-packages', 'yt-dlp'];

        $actualArgs = $this->downloader->getUpdateCommandArgs();

        $this->assertSame($expectedArgs, $actualArgs);
        $this->assertCount(5, $actualArgs);
    }

    public function testGetSupportedDomainsReturnsEmptyArray(): void
    {
        // YoutubeDlCliDownloader uses --simulate to check support dynamically
        // so getSupportedDomains returns an empty array
        $this->assertSame([], $this->downloader->getSupportedDomains());
    }

    public function testAddFilesLinksShortcutAndMediaFileOfFreshDownload(): void
    {
        $base = $this->createDownloadedFiles(desktop: true);
        $persistedFiles = $this->capturePersistedFiles();
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[info] Writing internet shortcut (.desktop) to: {$base}.desktop\n[download] Destination: {$base}.mp4\n"
        );

        $this->assertLinkedPaths(["{$base}.desktop", "{$base}.mp4"], $persistedFiles, $downloadJob);
        $this->assertSame('video-id', $persistedFiles["{$base}.mp4"]->getMetadata()['id']);
        $this->assertSame('video-id', $persistedFiles["{$base}.desktop"]->getMetadata()['id']);
    }

    public function testAddFilesLinksFilesThatWereAlreadyDownloaded(): void
    {
        $base = $this->createDownloadedFiles(desktop: true);
        $persistedFiles = $this->capturePersistedFiles();
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        // Output of a run without the download archive while the files exist
        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[info] Internet shortcut (.desktop) is already present\n[download] {$base}.mp4 has already been downloaded\n[download] 100% of    2.72MiB\n"
        );

        $this->assertLinkedPaths(["{$base}.desktop", "{$base}.mp4"], $persistedFiles, $downloadJob);
    }

    public function testAddFilesLinksAlreadyDownloadedMediaFileWithoutShortcut(): void
    {
        $base = $this->createDownloadedFiles(desktop: false);
        $persistedFiles = $this->capturePersistedFiles();
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[download] {$base}.mp4 has already been downloaded\n"
        );

        $this->assertLinkedPaths(["{$base}.mp4"], $persistedFiles, $downloadJob);
        $this->assertSame('video-id', $persistedFiles["{$base}.mp4"]->getMetadata()['id']);
    }

    public function testAddFilesLinksMediaFileWhenShortcutIsAlreadyKnown(): void
    {
        $base = $this->createDownloadedFiles(desktop: true);
        $knownShortcut = new DownloadedFile()->setPath("{$base}.desktop")->setVisible(true);
        $this->downloadedFileRepository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => $criteria['path'] === "{$base}.desktop" ? $knownShortcut : null);
        $persistedFiles = $this->capturePersistedFiles();
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[info] Writing internet shortcut (.desktop) to: {$base}.desktop\n"
        );

        $this->assertLinkedPaths(["{$base}.desktop", "{$base}.mp4"], $persistedFiles, $downloadJob);
        $this->assertSame($knownShortcut, $persistedFiles["{$base}.desktop"]);
    }

    public function testAddFilesCreatesOneDownloadedFilePerPath(): void
    {
        $base = $this->createDownloadedFiles(desktop: true);
        $createdFiles = [];
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$createdFiles) {
            if ($entity instanceof DownloadedFile) {
                $createdFiles[spl_object_id($entity)] = $entity->getPath();
            }
        });
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        // The media file is reported through the shortcut and the "already downloaded" line
        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[info] Writing internet shortcut (.desktop) to: {$base}.desktop\n[download] {$base}.mp4 has already been downloaded\n"
        );

        $this->assertEqualsCanonicalizing(["{$base}.desktop", "{$base}.mp4"], array_values($createdFiles));
    }

    public function testAddFilesIgnoresFilesThatDoNotExist(): void
    {
        $persistedFiles = $this->capturePersistedFiles();
        $downloadJob = new DownloadJob()->setUri('https://example.com/video');

        $this->downloader->addFilesToDownloadJobFromCommandOutput(
            $downloadJob,
            "[download] /nonexistent/video-id.mp4 has already been downloaded\n[download] video-id: Video has already been recorded in the archive\n"
        );

        $this->assertSame([], $persistedFiles->getArrayCopy());
    }

    public function testDownloadRetriesWithoutArchiveWhenSkippedByDownloadArchive(): void
    {
        [$downloader, $invocationLog] = $this->createDownloaderWithFakeBinary(
            "[download] video-id: Video has already been recorded in the archive"
        );

        $this->assertTrue($downloader->download(new DownloadJob()->setUri('https://example.com/video')));

        $invocations = file($invocationLog, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $invocations);
        $this->assertStringNotContainsString('--no-download-archive', $invocations[0]);
        $this->assertStringContainsString('--no-download-archive', $invocations[1]);
        $this->assertStringEndsWith('https://example.com/video', $invocations[1]);
    }

    public function testDownloadRunsOnceWhenNotSkippedByDownloadArchive(): void
    {
        [$downloader, $invocationLog] = $this->createDownloaderWithFakeBinary(
            '[download] Destination: /downloads/generic/example.com/video-id.mp4'
        );

        $this->assertTrue($downloader->download(new DownloadJob()->setUri('https://example.com/video')));

        $this->assertCount(1, file($invocationLog, FILE_IGNORE_NEW_LINES));
    }

    /**
     * Create video-id.mp4, video-id.info.json and optionally video-id.desktop in a temporary directory.
     *
     * @return string the path without extension
     */
    private function createDownloadedFiles(bool $desktop): string
    {
        $directory = $this->createTemporaryDirectory();
        $base = $directory.'/video-id';
        file_put_contents("{$base}.mp4", 'video');
        file_put_contents("{$base}.info.json", json_encode(['id' => 'video-id', 'ext' => 'mp4']));
        if ($desktop) {
            file_put_contents("{$base}.desktop", "[Desktop Entry]\n");
        }

        return $base;
    }

    /**
     * @return \ArrayObject<string, DownloadedFile> persisted DownloadedFiles by path, filled while the test runs
     */
    private function capturePersistedFiles(): \ArrayObject
    {
        $persistedFiles = new \ArrayObject();
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) use ($persistedFiles) {
            if ($entity instanceof DownloadedFile) {
                $persistedFiles[$entity->getPath()] = $entity;
            }
        });

        return $persistedFiles;
    }

    /**
     * @param list<string>                         $expectedPaths
     * @param \ArrayObject<string, DownloadedFile> $persistedFiles
     */
    private function assertLinkedPaths(array $expectedPaths, \ArrayObject $persistedFiles, DownloadJob $downloadJob): void
    {
        $this->assertEqualsCanonicalizing($expectedPaths, array_keys($persistedFiles->getArrayCopy()));
        foreach ($persistedFiles as $path => $file) {
            $this->assertTrue($file->getDownloadJob()->contains($downloadJob), "{$path} is not linked to the job");
        }
    }

    /**
     * A downloader whose yt-dlp binary is a shell script that logs its arguments and prints the given output,
     * unless it is called with --no-download-archive.
     *
     * @return array{YoutubeDlCliDownloader, string} the downloader and the path of the invocation log
     */
    private function createDownloaderWithFakeBinary(string $outputWithArchive): array
    {
        $directory = $this->createTemporaryDirectory();
        $invocationLog = $directory.'/invocations.log';
        $binary = $directory.'/yt-dlp';
        file_put_contents($binary, sprintf(
            "#!/bin/sh\necho \"\$*\" >> %s\ncase \" \$* \" in\n  *' --no-download-archive '*) echo '[download] /downloads/video-id.mp4 has already been downloaded' ;;\n  *) echo %s ;;\nesac\n",
            escapeshellarg($invocationLog),
            escapeshellarg($outputWithArchive)
        ));
        chmod($binary, 0700);

        $downloader = new YoutubeDlCliDownloader(
            $this->cache,
            $directory.'/config/yt-dlp.conf',
            $binary,
            $directory.'/downloads',
            $this->logger,
            $this->eventDispatcher,
            $this->downloadedFileRepository,
            $this->entityManager
        );

        return [$downloader, $invocationLog];
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/yt-dlp-test-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
