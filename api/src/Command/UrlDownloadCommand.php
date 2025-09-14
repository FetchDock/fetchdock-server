<?php

namespace App\Command;

use App\Factory\DownloaderFactory;
use App\Service\Downloader\DownloaderInterface;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:url:download',
    description: 'Send a URL to the download server for processing',
)]
class UrlDownloadCommand extends Command
{
    /**
     * @var \App\Service\Downloader\DownloaderInterface|mixed|null
     */
    private ?DownloaderInterface $downloader = null;

    public function __construct(
        private DownloaderFactory $downloaderCollection
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $downloaderCollection = $this->downloaderCollection;

        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL to download')
            ->addOption(
                name: 'downloader',
                mode: InputArgument::OPTIONAL,
                description: 'Downloader to use (if multiple are available for the URL)',
                suggestedValues: function(CompletionInput $input) use ($downloaderCollection) {
                    $suggestions = [];
                    foreach ($downloaderCollection->getEnabledDownloaders() as $downloader) {
                        $suggestions[] = $downloader->getIdentifier();
                    }
                    return $suggestions;
                }
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = $input->getArgument('url');

        $selectedDownloader = $input->getOption('downloader');
        if($selectedDownloader) {
            $this->downloader = $this->downloaderCollection->getDownloaderByIdentifier($selectedDownloader);
            if(!$this->downloader) {
                $io->error(sprintf('Downloader with identifier "%s" not found!', $selectedDownloader));
                return Command::FAILURE;
            }
            if(!$this->downloader->supportsUri(Utils::uriFor($url))) {
                $io->error(sprintf('Downloader with identifier "%s" does not support the given URL!', $selectedDownloader));
                return Command::FAILURE;
            }
        } else {
            // No downloader selected, try to find one that supports the URL
            $downloaders = $this->downloaderCollection->getDownloadersByUri(Utils::uriFor($url));

            // Just take the first one for now, later we can add a choice if multiple are found
            foreach($downloaders as $downloader) {
                $this->downloader = $downloader;
                break;
            }
        }


        $io->info(sprintf('Downloading URL: %s', $url));
        if($this->downloader->download(Utils::uriFor($url))) {
            $io->success('URL sent to download server successfully!');
            return Command::SUCCESS;
        } else {
            $io->error('Failed to send URL to download server!');
            return Command::FAILURE;
        }
    }
}
