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
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GalleryDlCliDownloader extends AbstractCliDownloader implements CliDownloaderInterface
{
    public function __construct(
        protected TagAwareCacheInterface $cache,
        #[Autowire(param: 'downloader.gallery_dl_cli.config_path')]
        protected string $configPath,
        #[Autowire(param: 'downloader.gallery_dl_cli.binary_path')]
        protected string $binaryPath,
        #[Autowire(param: 'downloader.gallery_dl_cli.downloads_dir')]
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
        return 'gallery-dl-cli';
    }

    public function supportsUri(UriInterface $uri): bool
    {
        $process = new Process(array_merge(
            [
                $this->binaryPath,
            ],
            [
                '--simulate',
                (string) $uri,
            ]
        ));
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

    public function getSupportedDomains(): array
    {
        $version = $this->getCurrentVersion();

        return $this->cache->get('gallery_dl_cli_supported_domains_'.$version, function (ItemInterface $item) {
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

    public function addFilesToDownloadJobFromCommandOutput(DownloadJob $downloadJob, string $commandOutput): void
    {
        // Convert \n to actual new lines
        $lines = explode("\n", $commandOutput);

        // Example output:
        // ./gallery-dl/kemono/patreon/123/123_filename.pdf
        // ./gallery-dl/kemono/patreon/123/123_filename.zip

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            // Remove leading ./ if present
            if (str_starts_with($line, './')) {
                $line = substr($line, 2);
            }
            // Remove leading "# ./" if present (some versions of gallery-dl do this)
            if (str_starts_with($line, '# ./')) {
                $line = substr($line, 4);
            }
            $filePath = $this->downloadPath.'/'.ltrim($line, '/');
            if (file_exists($filePath) && is_file($filePath)) {
                $downloadedFile = $this->downloadedFileRepository->findOneBy(['path' => $filePath]);
                if (!$downloadedFile) {
                    $downloadedFile = new DownloadedFile();
                    $downloadedFile->setPath($filePath);
                    $downloadedFile->setVisible(true);
                    // TODO: Create a foolproof way to extract metadata if available
                    // Corrent .metadata.json filename generation is not foolproof right now
                    $downloadedFile->setMetadata([]);
                    $this->entityManager->persist($downloadedFile);
                }
                $downloadedFile->addDownloadJob($downloadJob);
                $this->entityManager->persist($downloadJob);
                $this->entityManager->flush();
                $this->logger->info('Added file to download job: '.$filePath);
            } else {
                $this->logger->warning('File listed in command output does not exist: '.$filePath);
            }
        }
    }

    protected function getCommandOptions(DownloadJobInterface $downloadJob): array
    {
        $commandOptions = [];

        // Check if the downloadJob contains cookies
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
        // If you want to generate a default config, you can run the binary with --config-create and parse the output.
        // For simplicity, return an empty config or a default template.
        return '{}';
    }

    public function getCurrentVersion(): string
    {
        $versions = $this->getVersionFromPip('gallery-dl');
        if (null === $versions) {
            throw new \RuntimeException('Unable to determine installed gallery-dl version');
        }

        return $versions['installed'];
    }

    public function getLatestVersion(): string
    {
        $versions = $this->getVersionFromPip('gallery-dl');
        if (null === $versions) {
            throw new \RuntimeException('Unable to determine latest gallery-dl version');
        }

        return $versions['latest'];
    }

    public function getUpdateCommandArgs(): array
    {
        return $this->getPipUpdateCommandArgs('gallery-dl');
    }

    public function getVersionCommandArgs(): array
    {
        return [
            'gallery-dl',
            '--version',
        ];
    }
}
