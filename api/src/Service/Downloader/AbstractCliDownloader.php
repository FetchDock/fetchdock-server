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
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

abstract class AbstractCliDownloader implements DownloaderInterface
{
    protected const float TIMEOUT = 1800.0;
    protected const float IDLE_TIMEOUT = 300.0;

    public function __construct(
        protected TagAwareCacheInterface   $cache,
        protected EventDispatcherInterface $eventDispatcher,
        protected string                   $configPath,
        protected string                   $binaryPath,
        protected string                   $downloadPath,
        protected LoggerInterface          $logger
    )
    {
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

        $downloadProcess = new Process([
            $this->binaryPath,
            '--config', $this->configPath,
            '--verbose',
            $downloadJob->getUrl()->__toString()
        ], $this->downloadPath);

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
            throw new RuntimeException($downloadProcess->getErrorOutput());
        }
        return true;
    }

    protected function createConfigFileIfNotExists(): void
    {
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            $this->logger->debug('Creating config directory', ['path' => $configDir]);
            mkdir($configDir, 0755, true);
        }

        if (!file_exists($this->configPath)) {
            $this->logger->info('Creating config file', ['path' => $this->configPath]);
            file_put_contents($this->configPath, $this->getConfigFileContents());
        }
    }

    abstract protected function getConfigFileContents(): string;

    protected function createDownloadDirectoryIfNotExists(): void
    {
        if (!is_dir($this->downloadPath)) {
            $this->logger->debug('Creating download directory', ['path' => $this->downloadPath]);
            mkdir($this->downloadPath, 0755, true);
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
            throw new RuntimeException($process->getErrorOutput());
        }
        return trim($process->getOutput());
    }

    /**
     * Get the installed and latest versions of a pip package.
     *
     * Uses the 'pip index versions' command and caches results for 1 hour.
     *
     * @param string $package The pip package name (e.g., 'yt-dlp', 'gallery-dl')
     * @return array|null Array with 'installed' and 'latest' keys, or null on failure
     */
    protected function getVersionFromPip(string $package): ?array
    {
        return $this->cache->get("{$this->getIdentifier()}-version", function (ItemInterface $item) use ($package) {
            $item->tag(['cli-version', $package]);
            $item->expiresAfter(new \DateInterval('PT1H'));

            // Run and parse the output of 'pip index versions <package>' to extract installed and latest package versions.
            $process = new Process([
                'pip',
                'index',
                'versions',
                $package
            ]);

            $versions = [];

            $process->mustRun(function(string $type, string $buffer) use (&$versions) {
                if (Process::OUT === $type) {
                    if (str_contains($buffer, 'INSTALLED')) {
                        $versions['installed'] = trim(str_replace('INSTALLED:', '', $buffer));
                    }

                    if (str_contains($buffer, 'LATEST')) {
                        $versions['latest'] = trim(str_replace('LATEST:', '', $buffer));
                    }
                }
            });

            if($process->isSuccessful()) {
                return $versions;
            }

            return null;
        });
    }

    /**
     * Generate pip update command arguments for a package.
     *
     * @param string $package The pip package name to update.
     * @return array Command arguments for Process: ['pip', 'install', '--upgrade', $package]
     */
    protected function getPipUpdateCommandArgs(string $package): array
    {
        return [
            'pip',
            'install',
            '--upgrade',
            $package,
        ];
    }
}
