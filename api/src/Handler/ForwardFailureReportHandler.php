<?php

namespace App\Handler;

use App\Entity\FailureReport;
use App\Enum\FailureReportForwarding;
use App\Message\ForwardFailureReportMessage;
use App\Repository\FailureReportForwardingSettingsRepository;
use App\Service\FailureReport\CollectorClient;
use App\Service\FailureReport\CollectorException;
use App\Service\FailureReport\CollectorInstance;
use App\Service\FailureReport\FailureReportForwarder;
use App\Service\FailureReport\UpstreamReportPayload;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a pending report to the collector (POST /v1/reports). Reports that can't go ahead (forwarding disabled, a
 * consent or operator policy version the collector no longer accepts) are held for review with the reason. A refusal
 * marks the report failed; an unreachable collector throws, so Messenger retries (FailureReportForwardingFailedListener
 * marks it failed once retries are exhausted).
 */
#[AsMessageHandler]
final readonly class ForwardFailureReportHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FailureReportForwardingSettingsRepository $settingsRepository,
        private CollectorClient $client,
        private CollectorInstance $instance,
        private FailureReportForwarder $forwarder,
        private LoggerInterface $logger,
        #[Autowire('%app.version%')]
        private string $appVersion,
        #[Autowire('%app.failure_reports.enabled%')]
        private bool $reportsEnabled,
    ) {
    }

    public function __invoke(ForwardFailureReportMessage $message): void
    {
        $report = $this->entityManager->find(FailureReport::class, Uuid::fromString($message->reportId));
        if (null === $report || FailureReportForwarding::PENDING !== $report->getForwarding()) {
            // Withdrawn, or handled already
            return;
        }

        $settings = $this->settingsRepository->get();
        if (!$this->reportsEnabled || !$settings->isEnabled() || !$this->client->isConfigured()) {
            $this->hold($report, 'Forwarding is disabled.');

            return;
        }

        $operatorVersion = (string) $settings->getAcceptedPolicyVersion();
        try {
            $policy = $this->client->fetchPolicy();
        } catch (CollectorException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $this->fail($report, $e->getMessage());

            return;
        }
        if (!$policy->accepts($report->getUpstreamPolicyVersion())) {
            $this->hold($report, \sprintf('The collector no longer accepts the privacy policy version the reporter agreed to (%s). The report can\'t be forwarded.', $report->getUpstreamPolicyVersion()));

            return;
        }
        if (!$policy->accepts($operatorVersion)) {
            $this->hold($report, \sprintf('The collector no longer accepts the privacy policy version you accepted (%s). Accept version %s in the forwarding settings, then forward the report.', $operatorVersion, $policy->version));

            return;
        }

        $payload = UpstreamReportPayload::build($report, $this->appVersion, $operatorVersion);
        try {
            $upstreamId = $this->instance->call(fn (string $secret): Uuid => $this->client->submitReport($secret, $payload));
        } catch (CollectorException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $this->fail($report, $e->getMessage());

            return;
        }

        // The reporter may have withdrawn the report while it was being sent
        $stillThere = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM failure_report WHERE id = ?', [$report->getId()->toRfc4122()]);
        if (false === $stillThere) {
            $this->forwarder->deleteUpstream([$upstreamId]);

            return;
        }

        $report->markForwarded($upstreamId)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->logger->info('Failure report forwarded to the collector', ['report' => $message->reportId, 'upstream_id' => $upstreamId->toRfc4122()]);
    }

    private function hold(FailureReport $report, string $reason): void
    {
        $report->setForwarding(FailureReportForwarding::AWAITING_REVIEW, $reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->logger->notice('Failure report held for review', ['report' => $report->getId()->toRfc4122(), 'reason' => $reason]);
    }

    private function fail(FailureReport $report, string $reason): void
    {
        $report->setForwarding(FailureReportForwarding::FAILED, $reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->logger->warning('The collector refused a failure report', ['report' => $report->getId()->toRfc4122(), 'reason' => $reason]);
    }
}
