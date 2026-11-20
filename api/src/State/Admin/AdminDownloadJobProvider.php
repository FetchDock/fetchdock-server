<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\Repository\DownloadJobRepository;
use App\Resource\Admin\AdminDownloadJob;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /admin/download_jobs and /admin/download_jobs/{uuid}: any user's jobs, including soft-deleted ones.
 *
 * @implements ProviderInterface<AdminDownloadJob>
 */
final readonly class AdminDownloadJobProvider implements ProviderInterface
{
    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private EntityManagerInterface $entityManager,
        private AdminLookup $lookup,
        private AdminPaginator $paginator,
        private SoftDeleteFilter $softDeleteFilter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $job = $this->lookup->job($uriVariables['uuid'] ?? null);

            return $this->softDeleteFilter->disabled(static fn (): AdminDownloadJob => AdminDownloadJob::fromEntity($job));
        }

        $queryBuilder = $this->downloadJobRepository->createQueryBuilder('j')
            ->leftJoin('j.owner', 'o')
            ->addSelect('o')
            ->orderBy('j.createdAt', 'DESC')
            ->addOrderBy('j.id', 'DESC');

        if (null !== $owner = AdminPaginator::stringFilter($context, 'owner')) {
            $queryBuilder->andWhere('o.uuid = :owner')->setParameter('owner', $owner);
        }
        if (null !== $state = AdminPaginator::stringFilter($context, 'state')) {
            $queryBuilder->andWhere('j.state = :state')->setParameter('state', DownloadStateEnum::from((int) $state));
        }
        if (null !== $deleted = AdminPaginator::boolFilter($context, 'deleted')) {
            $queryBuilder->andWhere($deleted ? 'j.deletedAt IS NOT NULL' : 'j.deletedAt IS NULL');
        }
        if (null !== $uri = AdminPaginator::stringFilter($context, 'uri')) {
            $queryBuilder->andWhere('j.uri LIKE :uri')->setParameter('uri', '%'.addcslashes($uri, '%_\\').'%');
        }

        return $this->paginator->paginate($queryBuilder, $operation, $context, function (array $jobs): array {
            $fileCounts = $this->fileCounts($jobs);

            return array_map(static fn (DownloadJob $job): AdminDownloadJob => AdminDownloadJob::fromEntity($job, $fileCounts[$job->getId()] ?? 0), $jobs);
        });
    }

    /**
     * @param list<DownloadJob> $jobs
     *
     * @return array<int, int> the number of linked files (any state) by job ID, in one query
     */
    private function fileCounts(array $jobs): array
    {
        if ([] === $jobs) {
            return [];
        }

        $rows = $this->entityManager->createQuery(
            'SELECT j.id AS id, COUNT(f.id) AS files FROM '.DownloadJob::class.' j LEFT JOIN j.files f WHERE j.id IN (:ids) GROUP BY j.id'
        )->setParameter('ids', array_map(static fn (DownloadJob $job): ?int => $job->getId(), $jobs))->getArrayResult();

        return array_column(array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'files' => (int) $row['files']], $rows), 'files', 'id');
    }
}
