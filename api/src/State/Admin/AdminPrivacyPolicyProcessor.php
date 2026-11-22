<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Resource\Admin\AdminPrivacyPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PUT /admin/privacy_policy: saves the operator's answers without changing the published policy. Publishing a new
 * revision requires an explicit POST /admin/privacy_policy/publish.
 *
 * @implements ProcessorInterface<AdminPrivacyPolicy, AdminPrivacyPolicy>
 */
final readonly class AdminPrivacyPolicyProcessor implements ProcessorInterface
{
    public function __construct(
        private PrivacyPolicySettingsRepository $settingsRepository,
        private AdminPrivacyPolicyProvider $provider,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminPrivacyPolicy
    {
        $settings = $this->settingsRepository->get();
        $answers = $data->toAnswers();
        if ($answers->hash() !== $settings->getAnswers()->hash()) {
            $settings->setAnswers($answers);
            $this->entityManager->flush();
            $this->logger->info('Saved the privacy policy answers', [
                'missing' => $answers->missing(),
                'admin' => $this->security->getUser()?->getUserIdentifier(),
            ]);
        }

        return $this->provider->current();
    }
}
