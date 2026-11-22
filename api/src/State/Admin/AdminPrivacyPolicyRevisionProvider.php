<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\PrivacyPolicyRevisionRepository;
use App\Resource\Admin\AdminPrivacyPolicyRevision;
use App\Service\Privacy\PrivacyPolicyPublisher;

/**
 * GET /admin/privacy_policy/revisions[/{number}].
 *
 * @implements ProviderInterface<AdminPrivacyPolicyRevision>
 */
final readonly class AdminPrivacyPolicyRevisionProvider implements ProviderInterface
{
    public function __construct(
        private PrivacyPolicyRevisionRepository $repository,
        private PrivacyPolicyPublisher $publisher,
    ) {
    }

    /**
     * @return AdminPrivacyPolicyRevision|list<AdminPrivacyPolicyRevision>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminPrivacyPolicyRevision|array|null
    {
        $current = $this->publisher->currentRevision()?->getNumber();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn ($revision): AdminPrivacyPolicyRevision => AdminPrivacyPolicyRevision::fromEntity($revision, $revision->getNumber() === $current),
                $this->repository->findAllNewestFirst(),
            );
        }

        $revision = $this->repository->findOneByNumber((int) ($uriVariables['number'] ?? 0));

        return null === $revision ? null : AdminPrivacyPolicyRevision::fromEntity($revision, $revision->getNumber() === $current);
    }
}
