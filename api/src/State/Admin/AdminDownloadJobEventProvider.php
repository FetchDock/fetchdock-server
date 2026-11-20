<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\DownloadJobEvent;
use App\Repository\DownloadJobEventRepository;
use App\Resource\Admin\AdminDownloadJobEvent;

/**
 * GET /admin/download_jobs/{uuid}/events: the events of any user's job, including soft-deleted jobs.
 *
 * @implements ProviderInterface<AdminDownloadJobEvent>
 */
final readonly class AdminDownloadJobEventProvider implements ProviderInterface
{
    public function __construct(
        private DownloadJobEventRepository $downloadJobEventRepository,
        private AdminLookup $lookup,
        private AdminPaginator $paginator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $job = $this->lookup->job($uriVariables['uuid'] ?? null);

        $queryBuilder = $this->downloadJobEventRepository->createQueryBuilder('e')
            ->andWhere('e.downloadJob = :job')
            ->setParameter('job', $job->getId())
            ->orderBy('e.id', 'ASC');

        return $this->paginator->paginate(
            $queryBuilder,
            $operation,
            $context,
            static fn (array $events): array => array_map(static fn (DownloadJobEvent $event): AdminDownloadJobEvent => AdminDownloadJobEvent::fromEntity($event), $events),
        );
    }
}
