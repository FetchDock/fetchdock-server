<?php

namespace App\Command;

use App\Factory\DownloaderFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'core:downloaders:update',
    description: 'Updates all the downloaders used in the container without having to restart or pull images',
)]
class CoreDownloadersUpdateCommand extends Command
{
    public function __construct(
        private DownloaderFactory $downloaderFactory,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach($this->downloaderFactory->getCliDownloaders() as $downloader) {
            $process = new Process(
                $downloader->getUpdateCommandArgs()
            );
            
            $process->run(function ($type, $buffer) use ($io) {
                if (Process::ERR === $type) {
                    $io->error($buffer);
                }

                if (Process::OUT === $type) {
                    $io->note($buffer);
                }
            });

            if($process->isSuccessful()) {
                $io->success("Upgraded {$downloader->getIdentifier()} to {$downloader->getCurrentVersion()}");
            }
        }
    }
}
