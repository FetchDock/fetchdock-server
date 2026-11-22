<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Resource\Admin\AdminPrivacyPolicy;
use App\Service\Privacy\PrivacyPolicyPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /admin/privacy_policy/publish: explicitly approves and publishes the current generated privacy policy as a new
 * revision (ADR 0013).
 *
 * @implements ProcessorInterface<mixed, AdminPrivacyPolicy>
 */
final readonly class AdminPrivacyPolicyPublishProcessor implements ProcessorInterface
{
    public function __construct(
        private PrivacyPolicyPublisher $publisher,
        private AdminPrivacyPolicyProvider $provider,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminPrivacyPolicy
    {
        $revision = $this->publisher->publish();
        $this->logger->info('Published privacy policy revision', [
            'revision' => $revision->getNumber(),
            'reason' => $revision->getReason()->value,
            'textVersion' => $revision->getTextVersion(),
            'appVersion' => $revision->getAppVersion(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        return $this->provider->current();
    }
}
