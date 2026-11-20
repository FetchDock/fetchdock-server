<?php

namespace App\MessageHandler;

use App\Factory\DownloaderFactory;
use App\Message\UpdateDownloadersMessage;
use App\Service\Downloader\CliDownloaderInterface;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use App\Service\Site\SiteSynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class UpdateDownloadersMessageHandler
{
    public function __construct(
        private readonly DownloaderFactory $downloaderFactory,
        private readonly LoggerInterface $logger,
        private readonly SiteSynchronizer $siteSynchronizer,
        private readonly ?OptionsSchemaFactory $optionsSchemaFactory = null,
    ) {
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

        // Updated tools may have other options: regenerate the options schemas on next use
        $this->optionsSchemaFactory?->invalidate();

        // Updated tools may know new extractors and domains for the curated sites
        try {
            $this->siteSynchronizer->sync();
        } catch (\Throwable $e) {
            $this->logger->error('Site synchronisation after downloader update failed', ['exception' => $e]);
        }
    }
}
