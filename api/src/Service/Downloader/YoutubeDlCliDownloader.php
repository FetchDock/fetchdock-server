<?php

namespace App\Service\Downloader;

use App\Dto\CookieDTO;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadedFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UriInterface;
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

    public function supportsUri(UriInterface $uri): bool
    {
        $process = new Process([
            $this->binaryPath,
            '--simulate',
            (string) $uri,
        ]);
        try {
            $process->mustRun();

            return $process->isSuccessful();
        } catch (ProcessFailedException $e) {
            return false;
        }
    }

    public function supportsDownloadJob(DownloadJobInterface $downloadJob): bool
    {
        $process = new Process(array_merge(
            [
                $this->binaryPath,
            ],
            $this->getCommandOptions($downloadJob),
            [
                '--simulate',
                (string) $downloadJob->getUrl(),
            ]
        ));
        try {
            $process->mustRun();

            return $process->isSuccessful();
        } catch (ProcessFailedException $e) {
            return false;
        }
    }

    public function addFilesToDownloadJobFromCommandOutput(DownloadJob $downloadJob, string $commandOutput): void
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

    private function addFileToDownloadJobFromCommandOutput(DownloadJob $downloadJob, string $filePath): void
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
            foreach ($downloadJob->getCookies() as $cookie) {
                if($cookie instanceof CookieDTO) {
                    file_put_contents($cookieFilePath, $cookie->toNetscapeCookieLine(), FILE_APPEND);
                } else {
                    throw new \InvalidArgumentException('Cookies must be instances of CookieDTO');
                }
            }
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
