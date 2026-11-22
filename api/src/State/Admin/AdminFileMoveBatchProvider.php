<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\FileMoveBatch;
use App\Repository\FileMoveBatchRepository;
use App\Resource\Admin\AdminFileMoveBatch;

/**
 * @implements ProviderInterface<AdminFileMoveBatch>
 */
final readonly class AdminFileMoveBatchProvider implements ProviderInterface
{
    public function __construct(
        private FileMoveBatchRepository $batchRepository,
        private AdminPaginator $paginator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $batch = is_numeric($uriVariables['id'] ?? null) ? $this->batchRepository->find((int) $uriVariables['id']) : null;

            return null === $batch ? null : AdminFileMoveBatch::fromEntity($batch);
        }

        $queryBuilder = $this->batchRepository->createQueryBuilder('b')
            ->leftJoin('b.site', 's')
            ->addSelect('s')
            ->orderBy('b.id', 'DESC');
        $filters = $context['filters'] ?? [];
        if (\is_string($filters['site'] ?? null)) {
            $queryBuilder->andWhere('s.slug = :site')->setParameter('site', $filters['site']);
        }
        if (\is_string($filters['status'] ?? null)) {
            $queryBuilder->andWhere('b.status = :status')->setParameter('status', $filters['status']);
        }

        return $this->paginator->paginate(
            $queryBuilder,
            $operation,
            $context,
            static fn (array $batches): array => array_map(static fn (FileMoveBatch $batch): AdminFileMoveBatch => AdminFileMoveBatch::fromEntity($batch), $batches),
        );
    }
}
