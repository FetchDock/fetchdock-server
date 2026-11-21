<?php

namespace App\Command;

use App\Entity\Site;
use App\Repository\DownloadJobRepository;
use App\Repository\SiteRepository;
use App\Service\Canonical\CanonicalKeyGenerator;
use App\Service\Site\ExtractorMatcherInterface;
use App\Service\Site\SiteResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Fills the canonical key of download jobs created before #94 (and, with --force, recomputes all keys,
 * e.g. after changing canonicalization profiles). Idempotent: runs from the entrypoint after migrations.
 *
 * Existing duplicates (jobs of one owner for the same media) are reported, never merged.
 *
 * Memory stays flat on any number of jobs: rows are read as arrays in id-paged batches, and services
 * collecting per-query state (DBAL debug profiler, buffering log handlers, the entity manager) are reset
 * after each batch.
 */
#[AsCommand(
    name: 'app:jobs:canonicalize',
    description: 'Fill the canonical media keys of download jobs and report existing duplicates',
)]
final readonly class CanonicalizeJobsCommand
{
    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private SiteRepository $siteRepository,
        private CanonicalKeyGenerator $canonicalKeyGenerator,
        private ExtractorMatcherInterface $extractorMatcher,
        private LoggerInterface $logger,
        /** Clears per-request state between batches, like Messenger workers do (see __invoke()) */
        #[Autowire(service: 'services_resetter')]
        private ResetInterface $servicesResetter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Jobs per extractor helper run')]
        int $batch = 200,
        #[Option(description: 'Compute the keys without saving them')]
        bool $dryRun = false,
        #[Option(description: 'Recompute the keys of all jobs, not only missing ones')]
        bool $force = false,
    ): int {
        $batch = max(1, $batch);
        /** @var array<string, ?Site> $sites by host; looked up by known domain only, never created */
        $sites = [];
        $afterId = 0;
        $updated = 0;
        $skipped = 0;

        while ([] !== ($jobs = $this->downloadJobRepository->findCanonicalizationBatch($afterId, $batch, $force))) {
            $afterId = end($jobs)['id'];

            try {
                $matches = $this->extractorMatcher->canonicalBatch(array_column($jobs, 'uri'));
            } catch (\RuntimeException $e) {
                // Leave the keys empty (jobs keep matching by URI) rather than store URL keys that would hide the extractor identity
                $this->logger->error('Unable to canonicalize a batch of download jobs', ['exception' => $e]);
                $io->warning(sprintf('Extractor helper failed for %d jobs up to id %d; they are left as they are.', count($jobs), $afterId));
                $skipped += count($jobs);
                $this->servicesResetter->reset();
                continue;
            }

            $keys = [];
            foreach ($jobs as ['id' => $id, 'uri' => $uri]) {
                $host = SiteResolver::getHost($uri) ?? '';
                if (!array_key_exists($host, $sites)) {
                    $sites[$host] = '' === $host ? null : $this->siteRepository->findOneByDomain($host);
                }

                $key = $this->canonicalKeyGenerator->generate($uri, $sites[$host], $matches[$uri] ?? null);
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('#%d %s (%s, %s)', $id, $key->key, $key->layer, $key->profile));
                }
                $keys[$id] = $key->key;
            }
            if (!$dryRun) {
                $this->downloadJobRepository->updateCanonicalKeys($keys);
            }
            $updated += count($keys);

            // The cached sites stay usable once detached: only their loaded fields are read
            $this->servicesResetter->reset();
        }

        $io->success(sprintf('%s %d download jobs%s.', $dryRun ? 'Computed canonical keys of' : 'Canonicalized', $updated, $skipped > 0 ? sprintf(', skipped %d', $skipped) : ''));

        $duplicateGroups = $this->downloadJobRepository->countDuplicateCanonicalKeyGroups();
        if ($duplicateGroups > 0) {
            $io->note(sprintf('%d groups of jobs of one owner share a canonical key (existing duplicates, not merged).', $duplicateGroups));
            if ($io->isVerbose()) {
                $io->table(['Owner id', 'Canonical key', 'Jobs'], array_map(
                    static fn (array $group): array => [$group['owner_id'] ?? '-', $group['canonical_key'], $group['jobs']],
                    $this->downloadJobRepository->findDuplicateCanonicalKeyGroups(),
                ));
            }
        }

        return $skipped > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
