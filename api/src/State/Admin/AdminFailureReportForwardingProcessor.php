<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Repository\FailureReportForwardingSettingsRepository;
use App\Resource\Admin\AdminFailureReportForwarding;
use App\Service\FailureReport\CollectorClient;
use App\Service\FailureReport\CollectorException;
use App\Service\FailureReport\CollectorInstance;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * PUT /admin/failure_report_forwarding: enabling accepts the collector policy and registers the instance.
 *
 * @implements ProcessorInterface<AdminFailureReportForwarding, AdminFailureReportForwarding>
 */
final readonly class AdminFailureReportForwardingProcessor implements ProcessorInterface
{
    public function __construct(
        private FailureReportForwardingSettingsRepository $settingsRepository,
        private AdminFailureReportForwardingProvider $provider,
        private CollectorClient $client,
        private CollectorInstance $instance,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private LoggerInterface $logger,
        #[Autowire('%app.failure_reports.enabled%')]
        private bool $reportsEnabled,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminFailureReportForwarding
    {
        $settings = $this->settingsRepository->get();
        $settings->setMode($data->mode);

        if (!$data->enabled) {
            if ($settings->isEnabled()) {
                $this->logger->info('Failure report forwarding disabled', ['admin' => $this->security->getUser()?->getUserIdentifier()]);
            }
            $settings->setEnabled(false);
            $this->entityManager->flush();

            return $this->provider->current();
        }

        if (!$this->reportsEnabled || !$this->client->isConfigured()) {
            throw new ConflictHttpException('Forwarding is unavailable on this server: failure reports are disabled (FAILURE_REPORTS_ENABLED) or no collector is configured (FAILURE_REPORTS_COLLECTOR_URL).');
        }

        try {
            $policy = $this->client->fetchPolicy();
        } catch (CollectorException $e) {
            throw new ServiceUnavailableHttpException(300, 'The collector can\'t be reached: '.$e->getMessage(), $e);
        }

        $version = $data->acceptedPolicyVersion;
        $keepsAccepted = null !== $version && $version === $settings->getAcceptedPolicyVersion() && $policy->accepts($version);
        if ($version !== $policy->version && !$keepsAccepted) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation(\sprintf('Accept the collector\'s current privacy policy (version %s, %s) to enable forwarding.', $policy->version, $policy->url), null, [], null, 'acceptedPolicyVersion', $version)]));
        }

        if ($version === $policy->version) {
            $settings->acceptPolicy($version, $policy->url);
        }
        $this->entityManager->flush();

        try {
            $this->instance->ensureRegistered();
        } catch (CollectorException $e) {
            $settings->setEnabled(false);
            $this->entityManager->flush();
            if ($e->isRetryable()) {
                throw new ServiceUnavailableHttpException(300, 'The collector can\'t be reached: '.$e->getMessage(), $e);
            }
            throw new ConflictHttpException('The collector refused to register this instance: '.$e->getMessage(), $e);
        }

        if (!$settings->isEnabled()) {
            $this->logger->info('Failure report forwarding enabled', [
                'admin' => $this->security->getUser()?->getUserIdentifier(),
                'policy_version' => $settings->getAcceptedPolicyVersion(),
                'mode' => $settings->getMode()->value,
            ]);
        }
        $settings->setEnabled(true);
        $this->entityManager->flush();

        return $this->provider->current();
    }
}
