<?php

namespace App\Service\Integrity;

use App\Entity\InstanceIntegrityCheck;
use App\Model\IntegritySummary;
use App\Repository\InstanceIntegrityCheckRepository;

/**
 * The integrity status as reported to clients and administrators (ADR 0013): the effective status, the image's
 * manifest digest and commit, and the latest check of each container.
 */
class IntegrityReport
{
    /** @var array{digest: ?string, gitSha: ?string}|null */
    private ?array $manifestInfo = null;

    public function __construct(
        private readonly IntegrityStatusResolver $statusResolver,
        private readonly InstanceIntegrityCheckRepository $repository,
        private readonly IntegrityManifest $manifest,
    ) {
    }

    public function summary(\DateTimeImmutable $now = new \DateTimeImmutable()): IntegritySummary
    {
        $checks = $this->latestChecks($now);
        $info = $this->manifestInfo();

        $summary = new IntegritySummary();
        $summary->status = $this->statusResolver->effectiveStatus($now);
        $summary->manifestDigest = $info['digest'];
        $summary->gitSha = $info['gitSha'];
        $summary->checkedAt = ([] === $checks ? null : $checks[0]->getCheckedAt());

        return $summary;
    }

    /**
     * The latest check of each api and worker container within the status window, newest first.
     *
     * @return list<InstanceIntegrityCheck>
     */
    public function latestChecks(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        return $this->repository->findLatestPerContainerSince($now->modify(IntegrityStatusResolver::WINDOW));
    }

    /**
     * The digest and commit of this container's manifest, null without one (development).
     *
     * @return array{digest: ?string, gitSha: ?string}
     */
    private function manifestInfo(): array
    {
        if (null === $this->manifestInfo) {
            $content = is_file($this->manifest->path()) ? file_get_contents($this->manifest->path()) : false;
            $document = false === $content ? null : json_decode($content, true);
            $gitSha = \is_array($document) && \is_string($document['gitSha'] ?? null) && '' !== $document['gitSha'] ? $document['gitSha'] : null;
            $this->manifestInfo = [
                'digest' => false === $content ? null : hash('sha256', $content),
                'gitSha' => $gitSha,
            ];
        }

        return $this->manifestInfo;
    }
}
