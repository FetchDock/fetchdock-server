<?php

namespace App\Service\Downloader;

use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
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
        protected TagAwareCacheInterface   $cache,
        #[Autowire(param: 'downloader.yt_dlp_cli.config_path')]
        protected string                   $configPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.binary_path')]
        protected string                   $binaryPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.downloads_dir')]
        protected string                   $downloadPath,
        protected LoggerInterface          $logger,
        protected EventDispatcherInterface $eventDispatcher,
        protected DownloadedFileRepository $downloadedFileRepository,
        protected EntityManagerInterface   $entityManager
    )
    {
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
            $uri->__toString()
        ]);
        try {
            $process->mustRun();
            $this->logger->debug('yt-dlp output', [
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
            ]);
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
                                $actualFilePath = preg_replace('/\.desktop$/', '.' . $fileExt, $filePath);
                                $this->addFileToDownloadJobFromCommandOutput($downloadJob, $actualFilePath);
                            }

                            if (json_last_error() === JSON_ERROR_NONE) {
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
        return $this->getVersionFromPip('yt-dlp')['installed'];
    }

    public function getLatestVersion(): string
    {
        return $this->getVersionFromPip('yt-dlp')['latest'];
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
                    if (json_last_error() === JSON_ERROR_NONE) {
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
}
