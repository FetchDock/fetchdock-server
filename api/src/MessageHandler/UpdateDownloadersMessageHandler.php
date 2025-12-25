<?php

namespace App\MessageHandler;

use App\Factory\DownloaderFactory;
use App\Message\UpdateDownloadersMessage;
use App\Service\Downloader\CliDownloaderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class UpdateDownloadersMessageHandler
{
    public function __construct(
        private(set) DownloaderFactory $downloaderFactory,
        private(set) LoggerInterface   $logger,
    )
    {
    }

    public function __invoke(UpdateDownloadersMessage $message): void
    {
        foreach ($this->downloaderFactory->getEnabledDownloaders() as $downloader) {
            if ($downloader instanceof CliDownloaderInterface) {
                $process = new Process($downloader->getUpdateCommandArgs());

                $process->mustRun(function (string $type, string $buffer) {
                    $this->logger->debug('Downloader update output', [
                        'type' => $type,
                        'buffer' => $buffer,
                    ]);
                });
            }
        }
    }
}
