<?php

namespace App\Service\FailureReport;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\FailureReport;
use App\Entity\FailureReportUpstreamComment;
use App\Enum\FailureReportForwarding;
use App\Enum\FailureReportForwardingMode;
use App\Message\DeleteUpstreamReportMessage;
use App\Message\ForwardFailureReportMessage;
use App\Message\ForwardUpstreamCommentMessage;
use App\Repository\FailureReportForwardingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Forwarding of failure reports to the FetchDock collector (phase 2 of ADR 0012): what clients are offered, the
 * reporter's consent, the admin's review and comments, and withdrawals. The collector calls run in the handlers.
 */
class FailureReportForwarder
{
    public function __construct(
        private readonly CollectorClient $client,
        private readonly FailureReportForwardingSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        #[Autowire('%app.failure_reports.enabled%')]
        private readonly bool $reportsEnabled,
    ) {
    }

    /**
     * What clients may offer the reporter: the collector policy to agree to, or null when reports can't be shared.
     * Null as well while the policy the admin accepted is no longer honoured by the collector.
     *
     * @return array{privacyPolicyUrl: string, privacyPolicyVersion: string}|null
     */
    public function offer(): ?array
    {
        if (!$this->reportsEnabled || !$this->client->isConfigured()) {
            return null;
        }
        $settings = $this->settingsRepository->get();
        $accepted = $settings->getAcceptedPolicyVersion();
        if (!$settings->isEnabled() || null === $accepted) {
            return null;
        }

        $policy = $this->client->policy();
        if (null === $policy) {
            // The collector is unreachable right now; reports wait in the queue until it is back
            $url = $settings->getAcceptedPolicyUrl();

            return null === $url ? null : ['privacyPolicyUrl' => $url, 'privacyPolicyVersion' => $accepted];
        }

        return $policy->accepts($accepted) ? ['privacyPolicyUrl' => $policy->url, 'privacyPolicyVersion' => $policy->version] : null;
    }

    /**
     * Checks the reporter's consent on a new report and sets its forwarding state. 422 when it can't be shared.
     */
    public function requestForwarding(FailureReport $report): void
    {
        if (!$report->isShareUpstream()) {
            $report->setForwarding(FailureReportForwarding::NOT_REQUESTED);

            return;
        }

        $offer = $this->offer() ?? $this->invalid('shareUpstream', 'This server does not share reports with the FetchDock developers.');
        $version = $report->getUpstreamPolicyVersion();
        $accepted = $this->client->policy()?->accepts($version) ?? $version === $offer['privacyPolicyVersion'];
        if (!$accepted) {
            $this->invalid('upstreamPolicyVersion', 'The collector no longer accepts this version of its privacy policy. Reload the report form and agree to the current one.');
        }

        $report->setForwarding(FailureReportForwardingMode::AUTOMATIC === $this->settingsRepository->get()->getMode()
            ? FailureReportForwarding::PENDING
            : FailureReportForwarding::AWAITING_REVIEW);
    }

    /** Queues a stored report whose forwarding is pending */
    public function dispatchPending(FailureReport $report): void
    {
        if (FailureReportForwarding::PENDING === $report->getForwarding()) {
            $this->messageBus->dispatch(new ForwardFailureReportMessage($report->getId()->toRfc4122()));
        }
    }

    /**
     * An admin forwards a report awaiting review, or one that failed, with an optional comment for the developers.
     */
    public function forward(FailureReport $report, ?string $upstreamComment): void
    {
        if (!\in_array($report->getForwarding(), [FailureReportForwarding::AWAITING_REVIEW, FailureReportForwarding::FAILED], true)) {
            throw new ConflictHttpException('Only reports the reporter agreed to share that await review or failed can be forwarded.');
        }
        if (null === $this->offer()) {
            throw new ConflictHttpException('Forwarding is disabled, or the collector policy you accepted is no longer accepted by the collector.');
        }

        $report
            ->setUpstreamComment(null === $upstreamComment || '' === trim($upstreamComment) ? null : $upstreamComment)
            ->setForwarding(FailureReportForwarding::PENDING)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->dispatchPending($report);
    }

    /**
     * An admin adds a comment for the developers to a forwarded report.
     */
    public function comment(FailureReport $report, string $comment): FailureReportUpstreamComment
    {
        if (FailureReportForwarding::FORWARDED !== $report->getForwarding() || null === $report->getUpstreamId()) {
            throw new ConflictHttpException('Only forwarded reports can get comments for the developers.');
        }

        $upstreamComment = new FailureReportUpstreamComment($report, $comment);
        $report->addUpstreamComment($upstreamComment)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($upstreamComment);
        $this->entityManager->flush();
        $this->messageBus->dispatch(new ForwardUpstreamCommentMessage($upstreamComment->getId()->toRfc4122()));

        return $upstreamComment;
    }

    /**
     * Deletes forwarded reports at the collector, after their reporter withdrew them or deleted their account.
     *
     * @param iterable<Uuid> $upstreamIds
     */
    public function deleteUpstream(iterable $upstreamIds): void
    {
        $collectorUrl = $this->settingsRepository->get()->getCollectorUrl() ?? $this->client->getCollectorUrl();
        foreach ($upstreamIds as $upstreamId) {
            $this->messageBus->dispatch(new DeleteUpstreamReportMessage($upstreamId->toRfc4122(), $collectorUrl));
        }
    }

    private function invalid(string $path, string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, $path, null)]));
    }
}
