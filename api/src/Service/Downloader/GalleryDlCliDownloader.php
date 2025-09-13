<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GalleryDlCliDownloader implements DownloaderInterface
{
    public function __construct(
        private(set) TagAwareCacheInterface $cache,
        #[Autowire(param: 'downloader.gallery_dl_cli.config_path')]
        private(set) string $configPath,
        #[Autowire(param: 'downloader.gallery_dl_cli.binary_path')]
        private(set) string $binaryPath,
        private LoggerInterface $logger
    )
    {
    }

    public function download(UriInterface $uri): true
    {
        $this->createConfigFileIfNotExists();

        // TODO: Implement download() method.
        $downloadProcess = new Process([
            $this->binaryPath,
            '--config', $this->configPath,
            $uri->__toString()
        ]);
        $downloadProcess->mustRun();
        if (!$downloadProcess->isSuccessful()) {
            throw new \RuntimeException($downloadProcess->getErrorOutput());
        } else {
            return true;
        }
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function getSupportedDomains(): array
    {
        // Get the application version to set the cache key.
        $versionProcess = new Process([$this->binaryPath, '--version']);
        $versionProcess->mustRun();
        if (!$versionProcess->isSuccessful()) {
            throw new \RuntimeException($versionProcess->getErrorOutput());
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

    public function supportsUri(UriInterface $uri): bool
    {
        $supportedDomains = $this->getSupportedDomains();
        return in_array($uri->getHost(), $supportedDomains, true);
    }

    public function getIdentifier(): string
    {
        return 'gallery-dl-cli';
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
                throw new \RuntimeException($process->getErrorOutput());
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
                $this->logger->error('Failed to find the created gallery-dl config file.',[
                    'error_output' => $process->getErrorOutput(),
                    'output' => $output,
                    'matches' => $matches,
                ]);
                throw new \RuntimeException('Failed to create gallery-dl config file.');
            }
        }
    }


    private function fetchSupportedDomains(): array
    {
        $process = new Process([$this->binaryPath, '--list-extractors']);
        $process->mustRun();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
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
}
