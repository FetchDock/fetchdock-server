<?php

namespace App\Command;

use App\Service\Site\SiteSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sites:sync',
    description: 'Synchronise the curated sites (config/sites.yaml) and their extractors into the database',
)]
final readonly class SiteSyncCommand
{
    public function __construct(
        private SiteSynchronizer $siteSynchronizer,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->siteSynchronizer->sync();

        $io->success(sprintf('Synchronised %d curated sites with %d extractors.', $result['sites'], $result['extractors']));
        if ([] !== $result['removedSites']) {
            $io->note('Removed automatic sites now covered by curated sites: '.implode(', ', $result['removedSites']));
        }

        return Command::SUCCESS;
    }
}
