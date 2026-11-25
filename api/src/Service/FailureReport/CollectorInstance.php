<?php

namespace App\Service\FailureReport;

use App\Entity\FailureReportForwardingSettings;
use App\Repository\FailureReportForwardingSettingsRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * This instance's registration with the collector: registers on first use (POST /v1/instances) with the policy
 * version the admin accepted, and again when the collector rejects the stored secret (401), when the configured
 * collector changed, or when the secret can't be decrypted.
 */
class CollectorInstance
{
    public function __construct(
        private readonly CollectorClient $client,
        private readonly InstanceSecretBox $secretBox,
        private readonly FailureReportForwardingSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {
    }

    /**
     * Runs an authenticated collector call with the instance secret, registering first if needed. A rejected secret
     * leads to one new registration and one more attempt.
     *
     * @template T
     *
     * @param callable(string): T $call
     *
     * @return T
     *
     * @throws CollectorException
     */
    public function call(callable $call): mixed
    {
        $secret = $this->secret();
        try {
            return $call($secret);
        } catch (CollectorException $e) {
            if (!$e->isUnauthorized()) {
                throw $e;
            }
            $this->logger->warning('The collector rejected the instance secret, registering again');

            return $call($this->secret($secret));
        }
    }

    /**
     * Registers the instance unless it is registered with the configured collector already.
     *
     * @throws CollectorException
     */
    public function ensureRegistered(): void
    {
        $this->secret();
    }

    /**
     * The instance secret, after registering when there is none or it is $rejected.
     *
     * @throws CollectorException
     */
    private function secret(?string $rejected = null): string
    {
        $settings = $this->settingsRepository->get();
        $secret = $this->usableSecret($settings);
        if (null !== $secret && $secret !== $rejected) {
            return $secret;
        }

        // Another worker may be registering at the same time: lock the row and look again
        return $this->entityManager->wrapInTransaction(function () use ($rejected): string {
            $settings = $this->settingsRepository->get();
            $this->entityManager->refresh($settings, LockMode::PESSIMISTIC_WRITE);
            $secret = $this->usableSecret($settings);
            if (null !== $secret && $secret !== $rejected) {
                return $secret;
            }

            $policyVersion = $settings->getAcceptedPolicyVersion() ?? throw new CollectorException('Forwarding needs an accepted collector policy before the instance can register.', 409);
            $registration = $this->client->register($this->appVersion, $policyVersion);
            $settings->register(
                $this->client->getCollectorUrl(),
                $registration['instanceId'],
                $this->secretBox->encrypt($registration['instanceSecret']),
            );
            $this->entityManager->flush();
            $this->logger->info('Registered this instance with the collector', ['instance_id' => $registration['instanceId']->toRfc4122()]);

            return $registration['instanceSecret'];
        });
    }

    private function usableSecret(FailureReportForwardingSettings $settings): ?string
    {
        return $settings->isRegisteredWith($this->client->getCollectorUrl())
            ? $this->secretBox->decrypt($settings->getEncryptedInstanceSecret())
            : null;
    }
}
