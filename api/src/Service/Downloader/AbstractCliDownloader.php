<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

abstract class AbstractCliDownloader implements DownloaderInterface
{
    protected const float TIMEOUT = 1800.0;
    protected const float IDLE_TIMEOUT = 300.0;

    public function __construct(
        protected TagAwareCacheInterface $cache,
        protected string $configPath,
        protected string $binaryPath,
        protected string $downloadPath,
        protected LoggerInterface $logger
    ) {}

    abstract protected function getConfigFileContents(): string;

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

        $downloadProcess->mustRun(function (string $type, string $buffer) {
            if (Process::ERR === $type) {
                $this->logger->error($this->getIdentifier() . ' error output: ' . $buffer);
            } else {
                $this->logger->info($this->getIdentifier() . ' output: ' . $buffer);
            }
        });
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

    protected function createDownloadDirectoryIfNotExists(): void
    {
        if (!is_dir($this->downloadPath)) {
            mkdir($this->downloadPath, 0755, true);
        }
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

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::CLI_DOWNLOADER;
    }
}
