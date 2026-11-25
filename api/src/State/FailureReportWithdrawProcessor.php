<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\FailureReport;
use App\Service\FailureReport\FailureReportForwarder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DELETE /users/failure_reports/{id}: withdraws a report. It is deleted at once; a forwarded report is deleted at the
 * collector asynchronously (DeleteUpstreamReportMessage), which retries until the collector confirms.
 *
 * @implements ProcessorInterface<FailureReport, null>
 */
final readonly class FailureReportWithdrawProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private FailureReportForwarder $forwarder,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $upstreamId = $data->getUpstreamId();
        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        if (null !== $upstreamId) {
            $this->forwarder->deleteUpstream([$upstreamId]);
        }

        return null;
    }
}
