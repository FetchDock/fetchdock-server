<?php

namespace App\Command;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Factory\DownloaderFactory;
use App\Repository\DownloadJobEventRepository;
use App\Repository\DownloadJobRepository;
use App\Service\Downloader\AbstractCliDownloader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scan:downloaded-files',
    description: 'Add a short description for your command',
)]
class ScanDownloadedFilesCommand extends Command
{
    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private DownloadJobEventRepository $downloadJobEventRepository,
        private DownloaderFactory $downloaderFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get downloads without files
        $downloadJobsWithoutFiles = $this->downloadJobRepository->findWithoutFiles();

        /** @var DownloadJob $downloadJob */
        foreach ($downloadJobsWithoutFiles as $downloadJob) {
            if (DownloadStateEnum::COMPLETED !== $downloadJob->getState()) {
                $io->warning(sprintf('DownloadJob %d is in state %s, skipping.', $downloadJob->getId(), $downloadJob->getState()->label()));
                continue;
            }

            if (null === $downloadJob->getDownloader()) {
                $downloader = $this->downloaderFactory->getDownloadersByDownloadJob($downloadJob);
            } else {
                $downloader = $this->downloaderFactory->getDownloaderByIdentifier($downloadJob->getDownloader());
            }

            if (null === $downloader) {
                $io->error(sprintf('No downloader found for DownloadJob %d with downloader identifier %s, skipping.', $downloadJob->getId(), $downloadJob->getDownloader()));
                continue;
            }

            if ($downloader instanceof AbstractCliDownloader) {
                // $this->logger->info(sprintf('Scanning downloaded files for DownloadJob %d using downloader %s', $downloadJob->getId(), $downloader->getIdentifier()));
                $io->info(sprintf('Scanning downloaded files for DownloadJob %d using downloader %s', $downloadJob->getId(), $downloader->getIdentifier()));

                $downloadJobEvent = $this->downloadJobEventRepository->findOneBy([
                    'downloadJob' => $downloadJob,
                    'event' => 'process.stopped',
                    'source' => 'listener',
                    'updateMessage' => 'Process stopped with exit code 0 (OK)',
                ]);

                if (null === $downloadJobEvent) {
                    $io->error(sprintf('No download job event found for DownloadJob %d, skipping.', $downloadJob->getId()));
                    continue;
                }

                if (is_array($downloadJobEvent->getContext()['output'])) {
                    $cmdOutput = $downloadJobEvent->getContext()['output']['stdOut'];
                } elseif (is_string($downloadJobEvent->getContext()['output'])) {
                    $cmdOutput = $downloadJobEvent->getContext()['output'];
                } else {
                    $io->error(sprintf('No command output found for DownloadJob %d, skipping.', $downloadJob->getId()));
                    continue;
                }

                $downloader->addFilesToDownloadJobFromCommandOutput(
                    $downloadJob,
                    $cmdOutput
                );

                $io->success(sprintf('Added files to DownloadJob %d', $downloadJob->getId()));
            }
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
