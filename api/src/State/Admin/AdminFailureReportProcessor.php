<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Resource\Admin\AdminFailureReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PATCH /admin/failure_reports/{id}: triage (status and internal note).
 *
 * @implements ProcessorInterface<AdminFailureReport, AdminFailureReport>
 */
final readonly class AdminFailureReportProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminFailureReportProvider $provider,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminFailureReport
    {
        $report = $this->provider->find($uriVariables['id'] ?? null) ?? throw new NotFoundHttpException('Failure report not found.');

        $report
            ->setStatus($data->status)
            ->setAdminNote('' === $data->adminNote ? null : $data->adminNote)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return AdminFailureReport::fromEntity($report);
    }
}
