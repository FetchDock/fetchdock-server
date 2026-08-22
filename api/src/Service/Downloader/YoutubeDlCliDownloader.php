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
     * @param DownloadJobInterface $downloadJob
     * @return array|null
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

            if(!$success) {
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
        }
    }

    public function addFilesToDownloadJobFromCommandOutput(DownloadJobInterface $downloadJob, string $commandOutput): void
    {
        // Convert \n to actual new lines
        $lines = explode("\n", $commandOutput);

        // Look for the line that starts with [info] Writing internet shortcut (.desktop) to:
        // This file will contain a line like: Name={path to file}
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[info] Writing internet shortcut (.desktop) to: ')) {
                $filePath = trim(substr($line, strlen('[info] Writing internet shortcut (.desktop) to: ')));
                if (file_exists($filePath) && is_file($filePath)) {
                    $downloadedFile = $this->downloadedFileRepository->findOneBy(['path' => $filePath]);
                    if (!$downloadedFile) {
                        $downloadedFile = new DownloadedFile();
                        $downloadedFile->setPath($filePath);
                        $downloadedFile->setVisible(true);
                        // Trim the file extension for the name and append .info.json
                        // That file contains all the metadat in a JSON format
                        $metadataFilePath = preg_replace('/\.desktop$/', '.info.json', $filePath);
                        if (file_exists($metadataFilePath) && is_file($metadataFilePath)) {
                            $metadata = json_decode(file_get_contents($metadataFilePath), true);

                            // Get the extension from the metadata if it exists
                            $fileExt = $metadata['ext'] ?? null;
                            if ($fileExt) {
                                // Look for the file with the extension
                                $actualFilePath = preg_replace('/\.desktop$/', '.'.$fileExt, $filePath);
                                $this->addFileToDownloadJobFromCommandOutput($downloadJob, $actualFilePath);
                            }

                            if (JSON_ERROR_NONE === json_last_error()) {
                                $downloadedFile->setMetadata($metadata);
                            } else {
                                $downloadedFile->setMetadata([]);
                            }
                        }
                    }
                    $downloadedFile->addDownloadJob($downloadJob);
                    $this->entityManager->persist($downloadedFile);
                }
            }
        }
        $this->entityManager->persist($downloadJob);
        $this->entityManager->flush();
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

    private function addFileToDownloadJobFromCommandOutput(DownloadJobInterface $downloadJob, string $filePath): void
    {
        if (file_exists($filePath) && is_file($filePath)) {
            $downloadedFile = $this->downloadedFileRepository->findOneBy(['path' => $filePath]);
            if (!$downloadedFile) {
                $downloadedFile = new DownloadedFile();
                $downloadedFile->setPath($filePath);
                $downloadedFile->setVisible(true);
                // Trim the file extension for the name and append .info.json
                // That file contains all the metadat in a JSON format
                $metadataFilePath = preg_replace('/\.[^.]+$/', '.info.json', $filePath);
                if (file_exists($metadataFilePath) && is_file($metadataFilePath)) {
                    $metadata = json_decode(file_get_contents($metadataFilePath), true);
                    if (JSON_ERROR_NONE === json_last_error()) {
                        $downloadedFile->setMetadata($metadata);
                    } else {
                        $downloadedFile->setMetadata([]);
                    }
                }
            }
            $downloadedFile->addDownloadJob($downloadJob);
            $this->entityManager->persist($downloadedFile);
        }
    }

    protected function getCommandOptions(DownloadJobInterface $downloadJob): array
    {
        $commandOptions = [];

        if($downloadJob->getCookies()) {
            // Gallery-dl expects a cookie file in the Netscape cookies.txt format
            // So we'll create a temporary file with the cookies, pass it to the command, and delete it afterwards
            $cookieFilePath = tempnam(sys_get_temp_dir(), 'gallery_dl_cookies_');
            file_put_contents($cookieFilePath, $downloadJob->getCookiesNetscapeFileContent());
            $commandOptions = ['--cookies', $cookieFilePath];
        }

        if($downloadJob->getUserAgent()) {
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
