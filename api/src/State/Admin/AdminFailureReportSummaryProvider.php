<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\FailureReport;
use App\Enum\FailureReportKind;
use App\Resource\Admin\AdminFailureReportSummary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /admin/failure_reports/summary: report counts grouped by host, site and kind, in one query.
 *
 * @implements ProviderInterface<AdminFailureReportSummary>
 */
final readonly class AdminFailureReportSummaryProvider implements ProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<AdminFailureReportSummary>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('r.host AS host', 's.slug AS site', 'r.kind AS kind', 'COUNT(r.id) AS reports', 'MAX(r.createdAt) AS lastReportedAt')
            ->from(FailureReport::class, 'r')
            ->leftJoin('r.site', 's')
            ->groupBy('r.host', 's.slug', 'r.kind')
            ->orderBy('reports', 'DESC')
            ->addOrderBy('lastReportedAt', 'DESC')
            ->addOrderBy('r.host', 'ASC');

        if (null !== $since = AdminPaginator::stringFilter($context, 'since')) {
            $queryBuilder->andWhere('r.createdAt >= :since')->setParameter('since', new \DateTimeImmutable($since));
        }
        if (null !== $kind = AdminPaginator::stringFilter($context, 'kind')) {
            $queryBuilder->andWhere('r.kind = :kind')->setParameter('kind', FailureReportKind::from($kind));
        }

        return array_map(static function (array $row): AdminFailureReportSummary {
            $summary = new AdminFailureReportSummary();
            $summary->host = (string) $row['host'];
            $summary->site = $row['site'];
            $summary->kind = $row['kind'] instanceof FailureReportKind ? $row['kind'] : FailureReportKind::from((string) $row['kind']);
            $summary->count = (int) $row['reports'];
            $summary->lastReportedAt = new \DateTimeImmutable((string) $row['lastReportedAt']);

            return $summary;
        }, $queryBuilder->getQuery()->getArrayResult());
    }
}
