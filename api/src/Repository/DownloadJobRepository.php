<?php

namespace App\Repository;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadJob>
 */
class DownloadJobRepository extends ServiceEntityRepository
{
    /** Name of the gedmo soft-delete filter, see config/packages/doctrine.yaml */
    private const string SOFT_DELETE_FILTER = 'softdeleteable';
    /** Same media: same canonical key, or the same URI for jobs whose key isn't filled yet (created before #94) */
    private const string SAME_MEDIA = '(d.canonicalKey = :key OR (d.canonicalKey IS NULL AND d.uri = :uri))';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadJob::class);
    }

    public function findWithoutFiles()
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.files IS EMPTY')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the job's row still exists, bypassing the entity manager (whose identity map keeps deleted jobs) and the
     * soft-delete filter. A job is gone once its owner deleted their account (server#129).
     */
    public function existsInDatabase(int $id): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM download_job WHERE id = ?', [$id]);
    }

    public function findOneByUuidAndToken(string $uuid, string $token): ?DownloadJob
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.uuid = :uuid')
            ->andWhere('d.token = :token')
            ->setParameter('uuid', $uuid)
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The owner's jobs for the same media: same canonical key, or, for jobs created before canonical
     * keys existed (key not filled yet), the same URI.
     *
     * @return list<DownloadJob>
     */
    public function findByCanonicalKeyAndOwner(string $canonicalKey, string $uri, OidcSubjectIdentifier $owner): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere(self::SAME_MEDIA)
            ->andWhere('d.owner = :owner')
            ->setParameter('key', $canonicalKey)
            ->setParameter('uri', $uri)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult();
    }

    /**
     * Previously finished jobs for the same media (see findByCanonicalKeyAndOwner()) that have files
     * linked, newest first, with their files loaded.
     *
     * Soft-deleted jobs and files are included: a job deleted by its owner can still provide its files,
     * and soft-deleted files that are still on disk can be restored.
     * Jobs without files (e.g. repeat downloads created before files were reused) are excluded.
     * Whether the linked files still exist on disk is checked by the caller.
     *
     * @return list<DownloadJob>
     */
    public function findReusableByCanonicalKey(string $canonicalKey, string $uri): array
    {
        $filters = $this->getEntityManager()->getFilters();
        $softDeleteFilterWasEnabled = $filters->isEnabled(self::SOFT_DELETE_FILTER);
        if ($softDeleteFilterWasEnabled) {
            $filters->disable(self::SOFT_DELETE_FILTER);
        }

        try {
            return $this->createQueryBuilder('d')
                // Fetch the files in the same query, so the collection also contains soft-deleted files
                ->leftJoin('d.files', 'f')
                ->addSelect('f')
                ->andWhere(self::SAME_MEDIA)
                ->andWhere('d.state IN (:states)')
                ->andWhere('d.files IS NOT EMPTY')
                ->setParameter('key', $canonicalKey)
                ->setParameter('uri', $uri)
                ->setParameter('states', [DownloadStateEnum::COMPLETED->value, DownloadStateEnum::ALREADY_EXISTS->value])
                ->orderBy('d.id', 'DESC')
                ->getQuery()
                ->getResult();
        } finally {
            if ($softDeleteFilterWasEnabled) {
                $filters->enable(self::SOFT_DELETE_FILTER);
            }
        }
    }

    /**
     * Jobs without a canonical key (or all jobs with $all), by ascending id, after the given id.
     * Soft-deleted jobs are included: they can still provide files for reuse.
     *
     * @return list<array{id: int, uri: string}>
     */
    public function findCanonicalizationBatch(int $afterId, int $limit, bool $all): array
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'uri')
            ->from('download_job')
            ->where('id > :after')
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('after', $afterId);
        if (!$all) {
            $qb->andWhere('canonical_key IS NULL');
        }

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'uri' => (string) $row['uri']], $qb->fetchAllAssociative());
    }

    /**
     * @param array<int, string> $canonicalKeys job id => canonical key, written in one transaction
     */
    public function updateCanonicalKeys(array $canonicalKeys): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(static function () use ($connection, $canonicalKeys): void {
            foreach ($canonicalKeys as $id => $canonicalKey) {
                $connection->update('download_job', ['canonical_key' => $canonicalKey], ['id' => $id]);
            }
        });
    }

    /**
     * Groups of live jobs of one owner sharing a canonical key: duplicates created before #94.
     *
     * @return list<array{owner_id: int|null, canonical_key: string, jobs: int}>
     */
    public function findDuplicateCanonicalKeyGroups(int $limit = 50): array
    {
        return array_map(static fn (array $row): array => [
            'owner_id' => null === $row['owner_id'] ? null : (int) $row['owner_id'],
            'canonical_key' => (string) $row['canonical_key'],
            'jobs' => (int) $row['jobs'],
        ], $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT owner_id, canonical_key, COUNT(*) AS jobs FROM download_job
             WHERE canonical_key IS NOT NULL AND deleted_at IS NULL
             GROUP BY owner_id, canonical_key HAVING COUNT(*) > 1
             ORDER BY jobs DESC, canonical_key LIMIT '.max(1, $limit),
        ));
    }

    public function countDuplicateCanonicalKeyGroups(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM download_job WHERE canonical_key IS NOT NULL AND deleted_at IS NULL
             GROUP BY owner_id, canonical_key HAVING COUNT(*) > 1) duplicates',
        );
    }

    public function findByUrl(string $url): ?array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.uri = :uri')
            ->setParameter('uri', $url)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return DownloadJob[] Returns an array of DownloadJob objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?DownloadJob
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
