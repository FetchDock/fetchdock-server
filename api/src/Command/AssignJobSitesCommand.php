<?php

namespace App\Command;

use App\Repository\SiteRepository;
use App\Service\Site\SiteResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills DownloadJob.site for jobs created before #109, including soft-deleted ones. Only fills NULLs (idempotent).
 * Each host is resolved once: by a known site domain, then (unless --known-domains) through the site resolver,
 * whose host cache usually answers without running the extractor helper.
 */
#[AsCommand(
    name: 'app:jobs:assign-sites',
    description: 'Fill the site of download jobs that have none',
)]
final readonly class AssignJobSitesCommand
{
    public function __construct(
        private Connection $connection,
        private SiteRepository $siteRepository,
        private SiteResolver $siteResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Jobs per batch')]
        int $batch = 500,
        #[Option(description: 'Only match hosts against known site domains (fast, never runs the extractor helper)')]
        bool $knownDomains = false,
    ): int {
        $batch = max(1, $batch);
        /** @var array<string, int|null> $siteIds by host */
        $siteIds = [];
        $afterId = 0;
        $assigned = 0;
        $unresolved = 0;

        while ([] !== ($jobs = $this->connection->fetchAllAssociative(
            'SELECT id, uri FROM download_job WHERE site_id IS NULL AND id > :after ORDER BY id LIMIT '.$batch,
            ['after' => $afterId],
        ))) {
            $afterId = (int) end($jobs)['id'];

            /** @var array<int, list<int>> $jobIdsBySite */
            $jobIdsBySite = [];
            foreach ($jobs as ['id' => $id, 'uri' => $uri]) {
                $host = SiteResolver::getHost((string) $uri) ?? '';
                if (!\array_key_exists($host, $siteIds)) {
                    $siteIds[$host] = '' === $host ? null : $this->resolveSiteId($host, (string) $uri, $knownDomains);
                }
                if (null === $siteIds[$host]) {
                    ++$unresolved;
                    continue;
                }
                $jobIdsBySite[$siteIds[$host]][] = (int) $id;
            }

            foreach ($jobIdsBySite as $siteId => $jobIds) {
                $assigned += $this->connection->executeStatement(
                    'UPDATE download_job SET site_id = :site WHERE id IN (:ids) AND site_id IS NULL',
                    ['site' => $siteId, 'ids' => $jobIds],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }
        }

        $io->success(sprintf('Assigned a site to %d download jobs; %d jobs have no known site.', $assigned, $unresolved));

        return Command::SUCCESS;
    }

    private function resolveSiteId(string $host, string $uri, bool $knownDomains): ?int
    {
        $site = $this->siteRepository->findOneByDomain($host);
        if (null === $site && !$knownDomains) {
            try {
                $site = $this->siteResolver->resolve($uri);
            } catch (\RuntimeException $e) {
                $this->logger->warning('Unable to resolve the site of a host', ['host' => $host, 'exception' => $e]);
            }
        }

        return $site?->getId();
    }
}
