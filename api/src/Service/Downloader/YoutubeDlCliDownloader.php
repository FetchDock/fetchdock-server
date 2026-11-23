<?php

namespace App\Service\Downloader;

use App\Entity\DownloadedFile;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadedFileRepository;
use App\Service\Storage\DownloadLocationResolver;
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
        protected DownloadLocationResolver $locationResolver,
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
     * Prefix of the line yt-dlp prints for every media file once it's in its final place (--print after_move:...),
     * downloaded now or kept from a previous download. Example:
     * "[FetchDock] File: /downloads/generic/example.com/id.mp4".
     */
    private const string FILE_LINE_PREFIX = '[FetchDock] File: ';

    /**
     * The output of jobs from before #116, replayed by app:scan:downloaded-files: the media file was found through the
     * .desktop shortcut (--write-link) and the "ext" of the .info.json next to it. The shortcut itself isn't linked. Example:
     * "[info] Writing internet shortcut (.desktop) to: /downloads/generic/example.com/id.desktop".
     */
    private const string LEGACY_SHORTCUT_LINE_PREFIX = '[info] Writing internet shortcut (.desktop) to: ';

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
     * Report each media file on its own line. --print implies --quiet, which --no-quiet undoes to keep the log and progress.
     */
    protected function runDownloadProcess(DownloadJobInterface $downloadJob, array $extraOptions = []): Process
    {
        return parent::runDownloadProcess($downloadJob, [
            '--print', 'after_move:'.self::FILE_LINE_PREFIX.'%(filepath)s',
            '--no-quiet',
            ...$extraOptions,
        ]);
    }

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

            if (str_starts_with($line, self::FILE_LINE_PREFIX)) {
                $this->linkFile($downloadJob, substr($line, \strlen(self::FILE_LINE_PREFIX)));
            } elseif (str_starts_with($line, self::LEGACY_SHORTCUT_LINE_PREFIX)) {
                $this->linkMediaFileOfShortcut($downloadJob, substr($line, \strlen(self::LEGACY_SHORTCUT_LINE_PREFIX)));
            } elseif (1 === preg_match(self::ALREADY_DOWNLOADED_LINE_PATTERN, $line, $matches)) {
                $this->linkFile($downloadJob, $matches[1]);
            }
        }

        $this->filesByPath = [];
        $this->entityManager->persist($downloadJob);
        $this->entityManager->flush();
    }

    public function filePathsFromCommandOutput(DownloadJobInterface $downloadJob, string $commandOutput): array
    {
        $paths = [];
        foreach (explode("\n", $commandOutput) as $line) {
            $line = trim($line);
            if (str_starts_with($line, self::FILE_LINE_PREFIX)) {
                $paths[] = substr($line, \strlen(self::FILE_LINE_PREFIX));
            } elseif (1 === preg_match(self::ALREADY_DOWNLOADED_LINE_PATTERN, $line, $matches)) {
                $paths[] = $matches[1];
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Link the media file a legacy .desktop shortcut belongs to; its extension is the "ext" of the .info.json next to it.
     */
    private function linkMediaFileOfShortcut(DownloadJobInterface $downloadJob, string $shortcutPath): void
    {
        $extension = $this->readMetadataFile(preg_replace('/\.desktop$/', '.info.json', $shortcutPath))['ext'] ?? null;
        if (\is_string($extension) && '' !== $extension) {
            $this->linkFile($downloadJob, preg_replace('/\.desktop$/', '.'.$extension, $shortcutPath));
        }
    }

    /**
     * Link an existing file to the job, creating its DownloadedFile if it isn't known yet, with the .info.json next to it as metadata.
     */
    private function linkFile(DownloadJobInterface $downloadJob, string $path): void
    {
        if (!is_file($path)) {
            // The output of an older job (app:scan:downloaded-files) may point where a file move took the file from
            $location = $this->locationResolver->fromAbsolute($path);
            $movedFile = null === $location ? null : $this->downloadedFileRepository->findMovedFrom($location->directory, $location->relativePath);
            if (null !== $movedFile) {
                $movedFile->addDownloadJob($downloadJob);
                $this->entityManager->persist($movedFile);
                $this->filesByPath[$path] = $movedFile;
            }

            return;
        }

        $downloadedFile = $this->filesByPath[$path] ?? null;
        if (null === $downloadedFile) {
            $location = $this->locationResolver->fromAbsolute($path);
            if (null === $location) {
                return;
            }

            $downloadedFile = $this->downloadedFileRepository->findOneByLocation($location->directory, $location->relativePath);
            if (null === $downloadedFile) {
                $downloadedFile = new DownloadedFile()
                    ->setLocation($location->directory, $location->relativePath)
                    ->setVisible(true)
                    ->setMetadata($this->readMetadataFile(preg_replace('/\.[^.\/]+$/', '.info.json', $path)) ?? []);
            } elseif ($downloadedFile->isDeleted()) {
                // A location holds one row: a file no live job used anymore is in use again
                $downloadedFile->setDeletedAt(null);
            }
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

    /**
     * --paths overrides the config's --path; the download archive stays global, in the config's default directory.
     */
    protected function getTargetDirectoryArguments(string $targetPath): array
    {
        return ['--paths', $targetPath];
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

        if ($downloadJob->getReferrer()) {
            $commandOptions = array_merge($commandOptions, ['--referer', $downloadJob->getReferrer()]);
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
