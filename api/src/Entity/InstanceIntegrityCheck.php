<?php

namespace App\Entity;

use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Repository\InstanceIntegrityCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of app:integrity:check in an api or worker container (ADR 0013). The effective status of the instance is
 * the worst check of the last 48 hours (IntegrityStatusResolver).
 */
#[ORM\Entity(repositoryClass: InstanceIntegrityCheckRepository::class)]
#[ORM\Index(name: 'idx_instance_integrity_check_checked_at', columns: ['checked_at'])]
class InstanceIntegrityCheck
{
    /** Mismatched paths stored per check; the rest is only counted in the log. */
    public const int MAX_MISMATCHES = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<string> $mismatches
     */
    public function __construct(
        /** The container's host name */
        #[ORM\Column(length: 255)]
        private string $host,
        #[ORM\Column(length: 16, enumType: IntegrityRole::class)]
        private IntegrityRole $role,
        #[ORM\Column(length: 16, enumType: IntegrityStatus::class)]
        private IntegrityStatus $status,
        /** sha256 of the image's integrity-manifest.json, null without a manifest */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $manifestDigest,
        #[ORM\Column(length: 64)]
        private string $appVersion,
        /** The first MAX_MISMATCHES paths that differ from the manifest */
        #[ORM\Column(type: Types::JSON)]
        private array $mismatches = [],
        #[ORM\Column]
        private \DateTimeImmutable $checkedAt = new \DateTimeImmutable(),
    ) {
        $this->host = mb_substr($host, 0, 255);
        $this->appVersion = mb_substr($appVersion, 0, 64);
        $this->mismatches = \array_slice(array_values($mismatches), 0, self::MAX_MISMATCHES);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getRole(): IntegrityRole
    {
        return $this->role;
    }

    public function getStatus(): IntegrityStatus
    {
        return $this->status;
    }

    public function getManifestDigest(): ?string
    {
        return $this->manifestDigest;
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    /**
     * @return list<string>
     */
    public function getMismatches(): array
    {
        return $this->mismatches;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }
}
