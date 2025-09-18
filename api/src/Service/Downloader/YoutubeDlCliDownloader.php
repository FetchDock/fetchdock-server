<?php

namespace App\Service\Downloader;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class YoutubeDlCliDownloader extends AbstractCliDownloader implements DownloaderInterface
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
        protected EventDispatcherInterface $eventDispatcher
    )
    {
        parent::__construct($cache, $eventDispatcher, $configPath, $binaryPath, $downloadPath, $logger);
    }

    public function getIdentifier(): string
    {
        return 'yt-dlp-cli';
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
}
