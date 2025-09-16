<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GalleryDlCliDownloader implements DownloaderInterface
{
    /**
     * Maximum time in seconds to allow the process to run.
     * Currently set to 30 minutes.
     */
    private const float TIMEOUT = 1800.0;
    /**
     * Maximum time in seconds to allow the process to be idle (no output).
     * Currently set to 5 minute.
     */
    private const float IDLE_TIMEOUT = 300.0;

    public function __construct(
        private TagAwareCacheInterface $cache,
        #[Autowire(param: 'downloader.gallery_dl_cli.config_path')]
        private string                 $configPath,
        #[Autowire(param: 'downloader.gallery_dl_cli.binary_path')]
        private string                 $binaryPath,
        #[Autowire(param: 'downloader.gallery_dl_cli.downloads_dir')]
        private string                 $downloadPath,
        private LoggerInterface        $logger
    )
    {
    }

    public function download(DownloadJobInterface $downloadJob): true
    {
        $this->logger->debug(
            'Starting download with gallery-dl CLI',
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

        // Set a timeout of 30 minutes
        $downloadProcess->setTimeout(self::TIMEOUT);
        // Set an idle timeout of 5 minutes
        $downloadProcess->setIdleTimeout(self::IDLE_TIMEOUT);

        $downloadProcess->mustRun(function (string $type, string $buffer) {
            if (Process::ERR === $type) {
                $this->logger->error('gallery-dl error output: ' . $buffer);
            } else {
                $this->logger->info('gallery-dl output: ' . $buffer);
            }
        });
        if (!$downloadProcess->isSuccessful()) {
            throw new RuntimeException($downloadProcess->getErrorOutput());
        } else {
            return true;
        }
    }

    private function createConfigFileIfNotExists(): void
    {
        if (!file_exists($this->configPath)) {
            // Run the command to create a default config file.
            // gallery-dl --config-create
            // Since gallery-dl only writes the file to /etc/gallery-dl.conf by default,
            // we need to move it to the desired location.

            $process = new Process([$this->binaryPath, '--config-create']);
            $process->mustRun();
            if (!$process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput());
            }
            // Get the output file path from the command output.
            // Example output: "[config][info] Created a basic configuration file at '/etc/gallery-dl.conf'"
            $output = $process->getErrorOutput();
            preg_match("/'(.+?)'/", $output, $matches);
            if (isset($matches[1]) && file_exists($matches[1])) {
                // Make sure the target directory exists
                $targetDir = dirname($this->configPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                rename($matches[1], $this->configPath);
            } else {
                $this->logger->error('Failed to find the created gallery-dl config file.', [
                    'error_output' => $process->getErrorOutput(),
                    'output' => $output,
                    'matches' => $matches,
                ]);
                throw new RuntimeException('Failed to create gallery-dl config file.');
            }
        }
    }

    private function createDownloadDirectoryIfNotExists(): void
    {
        if (!is_dir($this->downloadPath)) {
            mkdir($this->downloadPath, 0755, true);
        }
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function supportsUri(UriInterface $uri): bool
    {
        $supportedDomains = $this->getSupportedDomains();
        return in_array($uri->getHost(), $supportedDomains, true);
    }

    public function getSupportedDomains(): array
    {
        // Get the application version to set the cache key.
        $versionProcess = new Process([$this->binaryPath, '--version']);
        $versionProcess->mustRun();
        if (!$versionProcess->isSuccessful()) {
            throw new RuntimeException($versionProcess->getErrorOutput());
        }
        $version = trim($versionProcess->getOutput());

        return $this->cache->get('gallery_dl_cli_supported_domains_' . $version, function (ItemInterface $item) {
            $item->tag([
                'downloader_supported_domains',
                'gallery_dl_cli_downloader',
            ]);
            // Cache for 24 hours
            $item->expiresAfter(86400);
            return $this->fetchSupportedDomains();
        });
    }

    private function fetchSupportedDomains(): array
    {
        $process = new Process([$this->binaryPath, '--list-extractors']);
        $process->mustRun();
        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }
        $output = $process->getOutput();
        /**
         * Example output:
         * {extractorName}
         * {extractorDescription}
         * Category: {category} - Subcategory: {subcategory}
         * Example :  {exampleUrl}
         */

        $lines = explode("\n", $output);
        $domains = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Example :')) {
                $exampleUrl = trim(substr($line, strlen('Example :')));
                $parsedUrl = parse_url($exampleUrl);
                if (isset($parsedUrl['host'])) {
                    $domains[] = $parsedUrl['host'];
                }
            }
        }

        $domains = array_unique($domains);
        sort($domains);
        return $domains;
    }

    public function getIdentifier(): string
    {
        return 'gallery-dl-cli';
    }

    public function getVersion(): string
    {
        $process = new Process([$this->binaryPath, '--version']);
        $process->mustRun();
        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }
        return trim($process->getOutput());
    }
}
