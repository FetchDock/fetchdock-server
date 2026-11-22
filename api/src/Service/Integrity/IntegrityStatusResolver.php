<?php

namespace App\Service\Integrity;

use App\Enum\IntegrityStatus;
use App\Repository\InstanceIntegrityCheckRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The instance's effective integrity status (ADR 0013): the worst check of the last 48 hours across all api and
 * worker containers, unknown when there is none. Containers check on start and the workers daily.
 */
class IntegrityStatusResolver
{
    public const string WINDOW = '-48 hours';

    public function __construct(
        private readonly InstanceIntegrityCheckRepository $repository,
        #[Autowire('%app.integrity.trust_dev%')]
        private readonly bool $trustDev = false,
    ) {
    }

    public function effectiveStatus(?\DateTimeImmutable $now = null): IntegrityStatus
    {
        if ($this->trustDev) {
            return IntegrityStatus::VERIFIED;
        }

        $since = ($now ?? new \DateTimeImmutable())->modify(self::WINDOW);

        return IntegrityStatus::worst(...array_map(
            static fn ($check): IntegrityStatus => $check->getStatus(),
            $this->repository->findSince($since),
        ));
    }
}
