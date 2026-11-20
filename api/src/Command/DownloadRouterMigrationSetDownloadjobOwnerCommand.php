<?php

namespace App\Command;

use App\Entity\OidcSubjectIdentifier;
use App\Repository\DownloadJobRepository;
use App\Repository\OidcSubjectIdentifierRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'download-router:migration:set-downloadjob-owner',
    description: 'Set the owner of (orphaned) download jobs after migrating to multi user setup',
)]
class DownloadRouterMigrationSetDownloadjobOwnerCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('owner-sub', InputArgument::OPTIONAL, 'The OidcSubject of the owner')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run, do not actually change anything')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ownerSub = $input->getArgument('owner-sub');
        $dryRun = $input->getOption('dry-run');

        $this->io = new SymfonyStyle($input, $output);

        if ($ownerSub) {
            $ownerSub = $this->oidcSubjectIdentifierRepository->findOneBy(['subject' => $ownerSub]);
            if (!$ownerSub) {
                throw new \RuntimeException('Owner sub not found');
            }
            $this->setOwnerOnDownloadJobs($ownerSub, $dryRun);
        } else {
            $ownerSubs = $this->oidcSubjectIdentifierRepository->findAll();

            $choices = array_map(fn ($s) => $s->getSubject(), $ownerSubs);
            $ownerSub = $this->io->choice('Select owner sub', $choices);
            if (!$ownerSub) {
                throw new \RuntimeException('Owner sub not selected');
            }

            if ($selectedOwnerSub = array_filter($ownerSubs, fn ($s) => $s->getSubject() === $ownerSub)) {
                $ownerSub = array_shift($selectedOwnerSub);

                $this->setOwnerOnDownloadJobs($ownerSub, $dryRun);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Assigns all download jobs without an owner to the given owner.
     *
     * Uses a single DQL bulk update instead of loading the jobs as entities: there can be tens of thousands of them
     * (memory), and updating them through the entity manager would trigger the entity listeners for every job, such
     * as API Platform's Mercure publisher and Timestampable.
     */
    private function setOwnerOnDownloadJobs(OidcSubjectIdentifier $owner, bool $dryRun = false): void
    {
        $count = (int) $this->downloadJobRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.owner IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        if ($dryRun) {
            $this->io->note(sprintf('Would set owner %s on %d download job(s) without an owner', $owner->getSubject(), $count));

            return;
        }

        $this->logger->info('Setting owner on download jobs without an owner', ['owner' => $owner->getSubject(), 'count' => $count]);

        $updated = $this->downloadJobRepository->createQueryBuilder('d')
            ->update()
            ->set('d.owner', ':owner')
            ->where('d.owner IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->execute();

        $this->io->success(sprintf('Set owner %s on %d download job(s)', $owner->getSubject(), $updated));
    }
}
