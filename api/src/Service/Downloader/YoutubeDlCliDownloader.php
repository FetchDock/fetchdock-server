<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class YoutubeDlCliDownloader implements DownloaderInterface
{
    public function __construct(
        private(set) TagAwareCacheInterface $cache,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'downloader.yt_dlp_cli.config_path')]
        private(set) string $configPath,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'downloader.yt_dlp_cli.binary_path')]
        private(set) string $binaryPath,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'downloader.yt_dlp_cli.downloads_dir')]
        private(set) string $downloadPath,
        private(set) LoggerInterface $logger
    )
    {
    }

    public function getIdentifier(): string
    {
        return 'yt-dlp-cli';
    }

    public function download(DownloadJobInterface $downloadJob): true
    {
        $this->logger->debug(
            'Starting download with yt-dlp CLI',
            [
                'url' => $downloadJob->getUrl()->__toString(),
                'configPath' => $this->configPath,
                'binaryPath' => $this->binaryPath,
                'downloadPath' => $this->downloadPath,
            ]
        );
        $this->createConfigFileIfNotExists();
        $this->createDownloadDirectoryIfNotExists();

        // TODO: Implement download() method.
        $downloadProcess = new Process([
            $this->binaryPath,
            '--config', $this->configPath,
            '--verbose',
            $downloadJob->getUrl()->__toString()
        ], $this->downloadPath);

        $downloadProcess->mustRun(function(string $type, string $buffer)  {
            if (Process::ERR === $type) {
                $this->logger->error('yt-dlp error output: ' . $buffer);
            } else {
                $this->logger->info('yt-dlp output: ' . $buffer);
            }
        });
        if (!$downloadProcess->isSuccessful()) {
            throw new \RuntimeException($downloadProcess->getErrorOutput());
        }
        return true;
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function getSupportedDomains(): array
    {
        return [];
    }

    public function supportsUri(UriInterface $uri): bool
    {
        // run yt-dlp --simulate {$uri} and check the exit code

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

    private function createDownloadDirectoryIfNotExists(): void
    {
        if (!is_dir($this->downloadPath)) {
            mkdir($this->downloadPath, 0755, true);
        }
    }

    private function createConfigFileIfNotExists(): void
    {
        // Create the directory if it does not exist
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            $this->logger->debug('Creating yt-dlp config directory', ['path' => $configDir]);
            mkdir($configDir, 0755, true);
        }

        if (!file_exists($this->configPath)) {
            $this->logger->info('Creating yt-dlp config file', ['path' => $this->configPath]);
            file_put_contents($this->configPath, <<<EOF
# yt-dlp configuration file
# For more information, see https://github.com/yt-dlp/yt-dlp?tab=readme-ov-file#configuration
# Example options:
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

## Abort if fragment is unavailable
--abort-on-unavailable-fragment
EOF

            );
        }
    }
}
