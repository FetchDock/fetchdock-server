<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GalleryDlCliDownloader extends AbstractCliDownloader implements DownloaderInterface
{
    public function __construct(
        protected TagAwareCacheInterface $cache,
        #[Autowire(param: 'downloader.yt_dlp_cli.config_path')]
        protected string $configPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.binary_path')]
        protected string $binaryPath,
        #[Autowire(param: 'downloader.yt_dlp_cli.downloads_dir')]
        protected string $downloadPath,
        protected LoggerInterface $logger
    )
    {
        parent::__construct($cache, $configPath, $binaryPath, $downloadPath, $logger);
    }

    public function getIdentifier(): string
    {
        return 'gallery-dl-cli';
    }

    protected function getConfigFileContents(): string
    {
        // If you want to generate a default config, you can run the binary with --config-create and parse the output.
        // For simplicity, return an empty config or a default template.
        return "{}";
    }

    public function getSupportedDomains(): array
    {
        $version = $this->getVersion();
        return $this->cache->get('gallery_dl_cli_supported_domains_' . $version, function (ItemInterface $item) {
            $item->tag([
                'downloader_supported_domains',
                'gallery_dl_cli_downloader',
            ]);
            $item->expiresAfter(86400);
            return $this->fetchSupportedDomains();
        });
    }

    private function fetchSupportedDomains(): array
    {
        $process = new Process([$this->binaryPath, '--list-extractors']);
        $process->mustRun();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
        $output = $process->getOutput();
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

    public function supportsUri(UriInterface $uri): bool
    {
        $supportedDomains = $this->getSupportedDomains();
        return in_array($uri->getHost(), $supportedDomains, true);
    }
}
