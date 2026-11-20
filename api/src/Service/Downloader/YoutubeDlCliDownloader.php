<?php

namespace App\Service\Downloader;

use App\Entity\DownloadedFile;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadedFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class YoutubeDlCliDownloader extends AbstractCliDownloader implements CliDownloaderInterface
{
    public function __construct(
        protected TagAwareCacheInterface $cache,
        #[Autowire(param: 'downloader.yt_dlp_cli.config_path')]
        protected string $configPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.binary_path')]
        protected string $binaryPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.downloads_dir')]
        protected string $downloadPath,
        protected LoggerInterface $logger,
        protected EventDispatcherInterface $eventDispatcher,
        protected DownloadedFileRepository $downloadedFileRepository,
        protected EntityManagerInterface $entityManager,
    ) {
        parent::__construct($cache, $eventDispatcher, $configPath, $binaryPath, $downloadPath, $logger);
    }

    public function getIdentifier(): string
    {
        return 'yt-dlp-cli';
    }

    public function getSupportedDomains(): array
    {
        return [];
    }

    /**
     * Runs YT-DLPs --dump-json command to get metadata for a given URL.
     * Returns the metadata as an associative array, or null if the command fails or the output is not valid JSON.
     *
     * @throws \JsonException
     */
    public function getMetadata(DownloadJobInterface $downloadJob): ?array
    {
        $uri = $downloadJob->getUrl();

        $process = new Process([
            $this->binaryPath,
            '-J',
            (string) $uri,
        ]);

        try {
            $process->mustRun();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                $metadata = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                if (JSON_ERROR_NONE === json_last_error()) {
                    return $metadata;
                }

                $this->logger->error('Failed to decode JSON metadata from yt-dlp output.', [
                    'uri' => (string) $uri,
                    'output' => $output,
                    'error' => json_last_error_msg(),
                ]);
            } else {
                $this->logger->error('yt-dlp failed to get metadata.', [
                    'uri' => (string) $uri,
                    'output' => $process->getOutput(),
                    'error' => $process->getErrorOutput(),
                    'exit_code' => $process->getExitCode(),
                ]);
            }
        } catch (ProcessFailedException $e) {
            $this->logger->error('yt-dlp failed to get metadata.', [
                'uri' => (string) $uri,
                'output' => $e->getProcess()->getOutput(),
                'error' => $e->getProcess()->getErrorOutput(),
                'exit_code' => $e->getProcess()->getExitCode(),
            ]);
        }

        return null;
    }

    public function supportsDownloadJob(DownloadJobInterface $downloadJob): bool
    {
        // Create a inline variable with the content of the

        $process = new Process(array_merge(
            [
                $this->binaryPath,
            ],
            $this->getCommandOptions($downloadJob),
            [
                '--simulate',
                '--verbose',
                (string) $downloadJob->getUrl(),
            ]
        ));
        try {
            $process->mustRun();

            $success = $process->isSuccessful();

            if (!$success) {
                $this->logger->debug('yt-dlp-cli failed.', [
                    'cli' => [
                        'cmd' => $process->getCommandLine(),
                        'output' => $process->getOutput(),
                        'error' => $process->getErrorOutput(),
                        'exit_code' => $process->getExitCode(),
                    ],
                    'uri' => $downloadJob->getUrl(),
                ]);
            }

            return $success;
        } catch (ProcessFailedException $e) {
            $this->logger->error('yt-dlp-cli failed.', [
                'cli' => [
                    'cmd' => $process->getCommandLine(),
                    'output' => $e->getProcess()->getOutput(),
                    'error' => $e->getProcess()->getErrorOutput(),
                    'exit_code' => $e->getProcess()->getExitCode(),
                ],
                'uri' => $downloadJob->getUrl(),
            ]);

            return false;
        } finally {
            $this->removeTemporaryFiles();
        }
    }

    /**
     * yt-dlp skipped the download because the video ID is in the shared download archive.
     * Example: "[download] sample-5s: sample-5s has already been recorded in the archive".
     */
    private const string ARCHIVE_SKIP_LINE_SUFFIX = 'has already been recorded in the archive';

    /**
     * A shortcut written for a fresh download. Example:
     * "[info] Writing internet shortcut (.desktop) to: /downloads/generic/example.com/id.desktop".
     */
    private const string SHORTCUT_LINE_PREFIX = '[info] Writing internet shortcut (.desktop) to: ';

    /**
     * A media file kept from a previous download (--no-force-overwrites). Example:
     * "[download] /downloads/generic/example.com/id.mp4 has already been downloaded".
     */
    private const string ALREADY_DOWNLOADED_LINE_PATTERN = '/^\[download\] (.+) has already been downloaded$/';

    /**
     * Files created or loaded while parsing the current command output, by path.
     * Prevents creating the same DownloadedFile twice before the entity manager is flushed.
     *
     * @var array<string, DownloadedFile>
     */
    private array $filesByPath = [];

    /**
     * The download archive makes yt-dlp skip a video it downloaded before, even when its files were removed since.
     * In that case, run this job once more without the archive: existing files are then reported (and not downloaded
     * again thanks to --no-force-overwrites), and missing files are downloaded again.
     */
    protected function runDownload(DownloadJobInterface $downloadJob): void
    {
        $process = $this->runDownloadProcess($downloadJob);

        if ($this->wasSkippedByDownloadArchive($process->getOutput())) {
            $this->logger->info('yt-dlp skipped the download because of the download archive, retrying this job without the archive', [
                'url' => (string) $downloadJob->getUrl(),
            ]);
            $this->runDownloadProcess($downloadJob, ['--no-download-archive']);
        }
    }

    private function wasSkippedByDownloadArchive(string $commandOutput): bool
    {
        foreach (explode("\n", $commandOutput) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[download] ') && str_ends_with($line, self::ARCHIVE_SKIP_LINE_SUFFIX)) {
                return true;
            }
        }

        return false;
    }

    public function addFilesToDownloadJobFromCommandOutput(DownloadJobInterface $downloadJob, string $commandOutput): void
    {
        $this->filesByPath = [];

        foreach (explode("\n", $commandOutput) as $line) {
            $line = trim($line);

            if (str_starts_with($line, self::SHORTCUT_LINE_PREFIX)) {
                $this->addShortcutAndMediaFiles($downloadJob, trim(substr($line, strlen(self::SHORTCUT_LINE_PREFIX))));
            } elseif (1 === preg_match(self::ALREADY_DOWNLOADED_LINE_PATTERN, $line, $matches)) {
                // An existing shortcut is reported without its path ("Internet shortcut (.desktop) is already present"),
                // so derive it from the media file
                $mediaPath = $matches[1];
                $shortcutPath = preg_replace('/\.[^.\/]+$/', '.desktop', $mediaPath);
                if (is_file($shortcutPath)) {
                    $this->addShortcutAndMediaFiles($downloadJob, $shortcutPath);
                }
                $this->linkFile($downloadJob, $mediaPath);
            }
        }

        $this->filesByPath = [];
        $this->entityManager->persist($downloadJob);
        $this->entityManager->flush();
    }

    /**
     * Link a .desktop shortcut and the media file it belongs to.
     * The media file's extension is read from the .info.json metadata next to the shortcut.
     */
    private function addShortcutAndMediaFiles(DownloadJobInterface $downloadJob, string $shortcutPath): void
    {
        $metadata = $this->readMetadataFile(preg_replace('/\.desktop$/', '.info.json', $shortcutPath));
        $this->linkFile($downloadJob, $shortcutPath, $metadata);

        $extension = $metadata['ext'] ?? null;
        if (is_string($extension) && '' !== $extension) {
            $this->linkFile($downloadJob, preg_replace('/\.desktop$/', '.'.$extension, $shortcutPath));
        }
    }

    /**
     * Link an existing file to the job, creating its DownloadedFile if it isn't known yet.
     * A new DownloadedFile gets the given metadata, or else the .info.json next to the file.
     */
    private function linkFile(DownloadJobInterface $downloadJob, string $path, ?array $metadata = null): void
    {
        if (!is_file($path)) {
            return;
        }

        $downloadedFile = $this->filesByPath[$path] ?? $this->downloadedFileRepository->findOneBy(['path' => $path]);
        if (null === $downloadedFile) {
            $downloadedFile = new DownloadedFile()
                ->setPath($path)
                ->setVisible(true)
                ->setMetadata($metadata ?? $this->readMetadataFile(preg_replace('/\.[^.\/]+$/', '.info.json', $path)) ?? []);
        }

        $downloadedFile->addDownloadJob($downloadJob);
        $this->entityManager->persist($downloadedFile);
        $this->filesByPath[$path] = $downloadedFile;
    }

    private function readMetadataFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($path), true);

        return is_array($metadata) ? $metadata : null;
    }

    public function getCurrentVersion(): string
    {
        $versions = $this->getVersionFromPip('yt-dlp');
        if (null === $versions) {
            throw new \RuntimeException('Unable to determine installed yt-dlp version');
        }

        return $versions['installed'];
    }

    public function getLatestVersion(): string
    {
        $versions = $this->getVersionFromPip('yt-dlp');
        if (null === $versions) {
            throw new \RuntimeException('Unable to determine latest yt-dlp version');
        }

        return $versions['latest'];
    }

    protected function getCommandOptions(DownloadJobInterface $downloadJob): array
    {
        $commandOptions = [];

        if ($downloadJob->getCookies()) {
            // yt-dlp expects a cookie file in the Netscape cookies.txt format.
            // The temporary file is removed by the caller via removeTemporaryFiles().
            $commandOptions = ['--cookies', $this->createCookieFile($downloadJob)];
        }

        if ($downloadJob->getUserAgent()) {
            $commandOptions = array_merge($commandOptions, ['--user-agent', $downloadJob->getUserAgent()]);
        }

        return $commandOptions;
    }

    protected function getConfigFileContents(): string
    {
        return <<<EOF
# yt-dlp configuration file
--path {$this->downloadPath}
--output %(extractor)s/%(webpage_url_domain)s/%(id)s.%(ext)s
--download-archive {$this->downloadPath}/download-api-yt-dlp-archive.txt
--restrict-filenames
--all-subs
--no-force-overwrites
--min-sleep-interval 1
--max-sleep-interval 10
--concurrent-fragments 4
--live-from-start
--file-access-retries 20
--fragment-retries 20
--no-skip-unavailable-fragments
--no-mtime
--write-description
--write-info-json
--write-playlist-metafiles
--write-thumbnail
--write-link
--write-subs
--check-formats
--convert-subs ass
--convert-thumbnails jpg
--abort-on-unavailable-fragment
EOF;
    }

    public function getUpdateCommandArgs(): array
    {
        return $this->getPipUpdateCommandArgs('yt-dlp');
    }

    public function getVersionCommandArgs(): array
    {
        return [
            'yt-dlp',
            '--version',
        ];
    }
}
