<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\FailureReport\AdminFailureReportForwardInput;
use App\Dto\FailureReport\AdminFailureReportUpstreamCommentInput;
use App\Resource\Admin\AdminFailureReport;
use App\Service\FailureReport\FailureReportForwarder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /admin/failure_reports/{id}/forward and /admin/failure_reports/{id}/upstream_comments (phase 2 of ADR 0012).
 *
 * @implements ProcessorInterface<AdminFailureReportForwardInput|AdminFailureReportUpstreamCommentInput, AdminFailureReport>
 */
final readonly class AdminFailureReportForwardProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminFailureReportProvider $provider,
        private FailureReportForwarder $forwarder,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminFailureReport
    {
        $report = $this->provider->find($uriVariables['id'] ?? null) ?? throw new NotFoundHttpException('Failure report not found.');

        if ($data instanceof AdminFailureReportUpstreamCommentInput) {
            $this->forwarder->comment($report, $data->comment);
            $this->logger->info('Admin added a comment for the collector', ['report' => $report->getId()->toRfc4122(), 'admin' => $this->security->getUser()?->getUserIdentifier()]);
        } else {
            $this->forwarder->forward($report, $data instanceof AdminFailureReportForwardInput ? $data->upstreamComment : null);
            $this->logger->info('Admin forwarded a failure report', ['report' => $report->getId()->toRfc4122(), 'admin' => $this->security->getUser()?->getUserIdentifier()]);
        }

        return AdminFailureReport::fromEntity($report);
    }
}
