<?php

namespace App\Service\Downloader;

use App\Entity\DownloadJob;
use App\Enum\DownloaderTypeEnum;
use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStartEvent;
use App\Event\CliProcessStdOutputEvent;
use App\Event\CliProcessStopEvent;
use App\Model\DownloadJobInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

abstract class AbstractCliDownloader implements DownloaderInterface
{
    protected const float TIMEOUT = 1800.0;
    protected const float IDLE_TIMEOUT = 300.0;

    /**
     * Temporary files (e.g. cookie files) created for the current CLI invocation.
     * They contain user secrets and must be removed once the process has finished.
     *
     * @var list<string>
     */
    private array $temporaryFiles = [];

    public function __construct(
        protected TagAwareCacheInterface $cache,
        protected EventDispatcherInterface $eventDispatcher,
        protected string $configPath,
        protected string $binaryPath,
        protected string $downloadPath,
        protected LoggerInterface $logger,
    ) {
    }

    abstract public function addFilesToDownloadJobFromCommandOutput(DownloadJob $downloadJob, string $commandOutput): void;

    public function download(DownloadJobInterface $downloadJob): true
    {
        $this->logger->debug(
            'Starting download with CLI',
            [
                'downloader' => $this->getIdentifier(),
                'url' => $downloadJob->getUrl()->__toString(),
                'configPath' => $this->configPath,
                'binaryPath' => $this->binaryPath,
                'downloadPath' => $this->downloadPath,
            ]
        );
        $this->createConfigFileIfNotExists();
        $this->createDownloadDirectoryIfNotExists();

        try {
            $this->runDownload($downloadJob);

            return true;
        } finally {
            $this->removeTemporaryFiles();
        }
    }

    /**
     * Run the download. Downloaders can override this to run the process more than once,
     * e.g. to retry with different options.
     */
    protected function runDownload(DownloadJobInterface $downloadJob): void
    {
        $this->runDownloadProcess($downloadJob);
    }

    /**
     * @param list<string> $extraOptions options added after the config, which take precedence over it
     */
    protected function runDownloadProcess(DownloadJobInterface $downloadJob, array $extraOptions = []): Process
    {
        $processOptions = array_merge(
            [
                $this->binaryPath,
            ],
            $this->getCommandOptions($downloadJob),
            [
                '--config', $this->configPath,
                '--verbose',
            ],
            $extraOptions,
            [
                $downloadJob->getUrl()->__toString(),
            ]
        );

        $downloadProcess = new Process($processOptions, $this->downloadPath);

        $downloadProcess->setTimeout(self::TIMEOUT);
        $downloadProcess->setIdleTimeout(self::IDLE_TIMEOUT);

        $this->eventDispatcher->dispatch(new CliProcessStartEvent(
            command: $downloadProcess->getCommandLine(),
            downloadJob: $downloadJob,
            process: $downloadProcess
        ));

        $downloadProcess->mustRun(function (string $type, string $buffer) use ($downloadJob, $downloadProcess) {
            if (Process::ERR === $type) {
                $this->eventDispatcher->dispatch(new CliProcessErrOutputEvent(
                    output: $buffer,
                    downloadJob: $downloadJob,
                    process: $downloadProcess
                ));
            } else {
                $this->eventDispatcher->dispatch(new CliProcessStdOutputEvent(
                    output: $buffer,
                    downloadJob: $downloadJob,
                    process: $downloadProcess
                ));
            }
        });

        $this->eventDispatcher->dispatch(new CliProcessStopEvent(
            downloadJob: $downloadJob,
            wasSuccessful: $downloadProcess->isSuccessful(),
            process: $downloadProcess,
            exitCode: $downloadProcess->getExitCode(),
            exitCodeText: $downloadProcess->getExitCodeText(),
            errorOutput: $downloadProcess->isSuccessful() ? null : $downloadProcess->getErrorOutput()
        ));

        if (!$downloadProcess->isSuccessful()) {
            throw new \RuntimeException($downloadProcess->getErrorOutput());
        }

        return $downloadProcess;
    }

    protected function createConfigFileIfNotExists(): void
    {
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            $this->logger->debug('Creating config directory', ['path' => $configDir]);
            if (!mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $configDir));
            }
        }

        if (!file_exists($this->configPath)) {
            $this->logger->info('Creating config file', ['path' => $this->configPath]);
            file_put_contents($this->configPath, $this->getConfigFileContents());
        }
    }

    /**
     * Write the job's cookies to a Netscape cookies.txt file readable only by the current user.
     * The file is tracked and removed by removeTemporaryFiles().
     */
    protected function createCookieFile(DownloadJobInterface $downloadJob): string
    {
        $cookieFilePath = tempnam(sys_get_temp_dir(), $this->getIdentifier().'_cookies_');
        if (false === $cookieFilePath) {
            throw new \RuntimeException('Unable to create temporary cookie file');
        }
        $this->temporaryFiles[] = $cookieFilePath;

        chmod($cookieFilePath, 0600);
        if (false === file_put_contents($cookieFilePath, $downloadJob->getCookiesNetscapeFileContent())) {
            throw new \RuntimeException('Unable to write temporary cookie file');
        }

        return $cookieFilePath;
    }

    protected function removeTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile) && !unlink($temporaryFile)) {
                $this->logger->error('Failed to remove temporary file', ['path' => $temporaryFile]);
            }
        }
        $this->temporaryFiles = [];
    }

    abstract protected function getConfigFileContents(): string;

    abstract protected function getCommandOptions(
        DownloadJobInterface $downloadJob,
    ): array;

    protected function createDownloadDirectoryIfNotExists(): void
    {
        if (!is_dir($this->downloadPath)) {
            $this->logger->debug('Creating download directory', ['path' => $this->downloadPath]);
            if (!mkdir($this->downloadPath, 0755, true) && !is_dir($this->downloadPath)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->downloadPath));
            }
        }
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }

    public function getCurrentVersion(): string
    {
        $process = new Process([$this->binaryPath, '--version']);
        $process->mustRun();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Get the installed and latest versions of a pip package.
     *
     * Uses the 'pip index versions' command and caches results for 5 minutes.
     *
     * @param string $package The pip package name (e.g., 'yt-dlp', 'gallery-dl')
     *
     * @return array{installed: string, latest: string}|null Array with 'installed' and 'latest' keys guaranteed, or null on failure
     */
    protected function getVersionFromPip(string $package): ?array
    {
        return $this->cache->get("{$this->getIdentifier()}-{$package}-version", function (ItemInterface $item) use ($package) {
            $item->tag(['cli-version', $package]);
            $item->expiresAfter(new \DateInterval('PT5M'));

            // Run and parse the output of 'pip index versions <package>' to extract installed and latest package versions.
            $process = new Process([
                'pip3',
                'index',
                'versions',
                $package,
            ]);

            $process->mustRun();

            $output = $process->getOutput();
            $this->logger->debug('Parsing pip version output', ['output' => $output]);

            $versions = [];

            if (preg_match('/INSTALLED:\s*(\S+)/', $output, $matches)) {
                $versions['installed'] = trim($matches[1]);
            } else {
                $this->logger->warning('Failed to parse INSTALLED version from pip output', ['output' => $output]);
            }

            if (preg_match('/LATEST:\s*(\S+)/', $output, $matches)) {
                $versions['latest'] = trim($matches[1]);
            } else {
                $this->logger->warning('Failed to parse LATEST version from pip output', ['output' => $output]);
            }

            if (isset($versions['installed']) && isset($versions['latest'])) {
                $this->logger->debug('Parsed pip versions successfully', ['versions' => $versions]);

                return $versions;
            }

            return null;
        });
    }

    /**
     * Generate pip update command arguments for a package.
     *
     * @param string $package the pip package name to update
     *
     * @return array Command arguments for Process: ['pip3', 'install', '--upgrade', $package]
     */
    protected function getPipUpdateCommandArgs(string $package): array
    {
        return [
            'pip3',
            'install',
            '--upgrade',
            '--break-system-packages',
            $package,
        ];
    }
}
