<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Resource\Admin\AdminDownloadedFile;

/**
 * GET /admin/downloaded_files, /admin/downloaded_files/{id} and /admin/download_jobs/{uuid}/files:
 * any file, including soft-deleted and hidden ones.
 *
 * @implements ProviderInterface<AdminDownloadedFile>
 */
final readonly class AdminDownloadedFileProvider implements ProviderInterface
{
    public function __construct(
        private DownloadedFileRepository $downloadedFileRepository,
        private AdminLookup $lookup,
        private AdminPaginator $paginator,
        private SoftDeleteFilter $softDeleteFilter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $file = $this->lookup->file($uriVariables['id'] ?? null);

            return $this->softDeleteFilter->disabled(static fn (): AdminDownloadedFile => AdminDownloadedFile::fromEntity($file));
        }

        $queryBuilder = $this->downloadedFileRepository->createQueryBuilder('f')
            // All linked jobs and their owners, for the "jobs" field
            ->leftJoin('f.downloadJob', 'j')
            ->addSelect('j')
            ->leftJoin('j.owner', 'o')
            ->addSelect('o')
            ->orderBy('f.id', 'DESC');

        $job = null;
        if (array_key_exists('uuid', $uriVariables)) {
            // /admin/download_jobs/{uuid}/files
            $job = $this->lookup->job($uriVariables['uuid']);
            $queryBuilder
                ->andWhere(':job MEMBER OF f.downloadJob')
                ->setParameter('job', $job->getId())
                ->orderBy('f.id', 'ASC');
        }

        if (null !== $deleted = AdminPaginator::boolFilter($context, 'deleted')) {
            $queryBuilder->andWhere($deleted ? 'f.deletedAt IS NOT NULL' : 'f.deletedAt IS NULL');
        }
        if (null !== $orphaned = AdminPaginator::boolFilter($context, 'orphaned')) {
            $liveJob = 'SELECT live.id FROM '.DownloadJob::class.' live WHERE live MEMBER OF f.downloadJob AND live.deletedAt IS NULL';
            $queryBuilder->andWhere($orphaned ? "NOT EXISTS ($liveJob)" : "EXISTS ($liveJob)");
        }

        return $this->paginator->paginate(
            $queryBuilder,
            $operation,
            $context,
            static fn (array $files): array => array_map(static fn (DownloadedFile $file): AdminDownloadedFile => AdminDownloadedFile::fromEntity($file, $job), $files),
        );
    }
}
