<?php

namespace App\Service\Integrity;

use App\Entity\InstanceIntegrityCheck;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Repository\InstanceIntegrityCheckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks the container's files against the image's manifest and the manifest against the official digest, and
 * records the result (ADR 0013):
 * 1. no manifest (dev images, custom builds) → unknown;
 * 2. a file differs from the manifest, or the manifest is unreadable or for another version → modified;
 * 3. the manifest digest differs from the one CI published for APP_VERSION → modified;
 * 4. the official digest can't be fetched (offline, pruned, a fork's build) or INTEGRITY_CHECK_ONLINE=false → unknown;
 * 5. otherwise verified.
 */
class IntegrityChecker
{
    /** Checks older than this are deleted; the effective status only looks at the last 48 hours. */
    public const string RETENTION = '-30 days';

    public function __construct(
        private readonly IntegrityManifest $manifest,
        private readonly OfficialDigestProvider $officialDigests,
        private readonly InstanceIntegrityCheckRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
        #[Autowire('%app.integrity.check_online%')]
        private readonly bool $online,
    ) {
    }

    public function check(IntegrityRole $role, ?string $host = null): InstanceIntegrityCheck
    {
        [$status, $digest, $mismatches] = $this->evaluate();

        $check = new InstanceIntegrityCheck(
            host: $host ?? (gethostname() ?: 'unknown'),
            role: $role,
            status: $status,
            manifestDigest: $digest,
            appVersion: $this->appVersion,
            mismatches: $mismatches,
        );
        $this->entityManager->persist($check);
        $this->entityManager->flush();
        $this->repository->deleteOlderThan(new \DateTimeImmutable(self::RETENTION));

        $context = ['status' => $status->value, 'role' => $role->value, 'version' => $this->appVersion, 'manifestDigest' => $digest, 'mismatches' => \count($mismatches)];
        if (IntegrityStatus::MODIFIED === $status) {
            $this->logger->warning('Instance integrity check: modified', $context + ['paths' => \array_slice($mismatches, 0, InstanceIntegrityCheck::MAX_MISMATCHES)]);
        } else {
            $this->logger->info('Instance integrity check: '.$status->value, $context);
        }

        return $check;
    }

    /**
     * @return array{IntegrityStatus, ?string, list<string>}
     */
    private function evaluate(): array
    {
        $path = $this->manifest->path();
        if (!is_file($path)) {
            return [IntegrityStatus::UNKNOWN, null, []];
        }

        $content = file_get_contents($path);
        $digest = false === $content ? null : hash('sha256', $content);
        $document = false === $content ? null : json_decode($content, true);
        if (null === $digest || !\is_array($document) || !\is_array($document['files'] ?? null)) {
            return [IntegrityStatus::MODIFIED, $digest, [IntegrityManifest::MANIFEST_FILE]];
        }

        $mismatches = IntegrityManifest::diff($document['files'], $this->manifest->hashFiles());
        if (($document['version'] ?? null) !== $this->appVersion) {
            array_unshift($mismatches, IntegrityManifest::MANIFEST_FILE);
        }
        if ([] !== $mismatches) {
            return [IntegrityStatus::MODIFIED, $digest, $mismatches];
        }

        if (!$this->online) {
            return [IntegrityStatus::UNKNOWN, $digest, []];
        }

        $official = $this->officialDigests->fetch($this->appVersion, $digest);
        if (null === $official) {
            return [IntegrityStatus::UNKNOWN, $digest, []];
        }
        if (!hash_equals($official, $digest)) {
            return [IntegrityStatus::MODIFIED, $digest, [IntegrityManifest::MANIFEST_FILE]];
        }

        return [IntegrityStatus::VERIFIED, $digest, []];
    }
}
