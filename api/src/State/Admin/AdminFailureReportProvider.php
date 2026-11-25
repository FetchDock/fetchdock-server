<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\FailureReport;
use App\Enum\FailureReportForwarding;
use App\Enum\FailureReportKind;
use App\Enum\FailureReportStatus;
use App\Repository\FailureReportRepository;
use App\Resource\Admin\AdminFailureReport;
use Symfony\Component\Uid\Uuid;

/**
 * GET /admin/failure_reports and /admin/failure_reports/{id}: any user's reports, without the reporter or the job.
 *
 * @implements ProviderInterface<AdminFailureReport>
 */
final readonly class AdminFailureReportProvider implements ProviderInterface
{
    public function __construct(
        private FailureReportRepository $failureReportRepository,
        private AdminPaginator $paginator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $report = $this->find($uriVariables['id'] ?? null);

            return null === $report ? null : AdminFailureReport::fromEntity($report);
        }

        $queryBuilder = $this->failureReportRepository->createQueryBuilder('r')
            ->leftJoin('r.site', 's')
            ->addSelect('s')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (null !== $kind = AdminPaginator::stringFilter($context, 'kind')) {
            $queryBuilder->andWhere('r.kind = :kind')->setParameter('kind', FailureReportKind::from($kind));
        }
        if (null !== $status = AdminPaginator::stringFilter($context, 'status')) {
            $queryBuilder->andWhere('r.status = :status')->setParameter('status', FailureReportStatus::from($status));
        }
        if (null !== $site = AdminPaginator::stringFilter($context, 'site')) {
            $queryBuilder->andWhere('s.slug = :site')->setParameter('site', $site);
        }
        if (null !== $host = AdminPaginator::stringFilter($context, 'host')) {
            $queryBuilder->andWhere('r.host = :host')->setParameter('host', strtolower($host));
        }

        if (null !== $forwarding = AdminPaginator::stringFilter($context, 'forwarding')) {
            $queryBuilder->andWhere('r.forwarding = :forwarding')->setParameter('forwarding', FailureReportForwarding::from($forwarding));
        }

        return $this->paginator->paginate(
            $queryBuilder,
            $operation,
            $context,
            static fn (array $reports): array => array_map(static fn (FailureReport $report): AdminFailureReport => AdminFailureReport::fromEntity($report), $reports),
        );
    }

    public function find(mixed $id): ?FailureReport
    {
        return is_string($id) && Uuid::isValid($id) ? $this->failureReportRepository->find(Uuid::fromString($id)) : null;
    }
}
