<?php

namespace App\Entity;

use App\Enum\FailureReportForwardingMode;
use App\Repository\FailureReportForwardingSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Forwarding of failure reports to the FetchDock collector (phase 2 of ADR 0012), a single row with the id ID: whether
 * the admin enabled it, the collector policy version they accepted, and the instance's registration with the collector.
 *
 * The instance secret is stored encrypted (InstanceSecretBox) and never serialized.
 */
#[ORM\Entity(repositoryClass: FailureReportForwardingSettingsRepository::class)]
class FailureReportForwardingSettings
{
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(length: 16, enumType: FailureReportForwardingMode::class, options: ['default' => 'review'])]
    private FailureReportForwardingMode $mode = FailureReportForwardingMode::REVIEW;

    /** The collector policy version the admin accepted when enabling forwarding */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $acceptedPolicyVersion = null;

    /** The human-readable page of that policy, in case the collector can't be reached */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $acceptedPolicyUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** The collector the instance registered with; a different configured collector means registering again */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $collectorUrl = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $instanceId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedInstanceSecret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getMode(): FailureReportForwardingMode
    {
        return $this->mode;
    }

    public function setMode(FailureReportForwardingMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getAcceptedPolicyVersion(): ?string
    {
        return $this->acceptedPolicyVersion;
    }

    public function getAcceptedPolicyUrl(): ?string
    {
        return $this->acceptedPolicyUrl;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function acceptPolicy(string $version, string $url, \DateTimeImmutable $now = new \DateTimeImmutable()): static
    {
        if ($version !== $this->acceptedPolicyVersion) {
            $this->acceptedAt = $now;
        }
        $this->acceptedPolicyVersion = $version;
        $this->acceptedPolicyUrl = $url;

        return $this;
    }

    public function getCollectorUrl(): ?string
    {
        return $this->collectorUrl;
    }

    public function getInstanceId(): ?Uuid
    {
        return $this->instanceId;
    }

    public function getEncryptedInstanceSecret(): ?string
    {
        return $this->encryptedInstanceSecret;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function isRegisteredWith(string $collectorUrl): bool
    {
        return null !== $this->instanceId && null !== $this->encryptedInstanceSecret && $collectorUrl === $this->collectorUrl;
    }

    public function register(string $collectorUrl, Uuid $instanceId, string $encryptedSecret, \DateTimeImmutable $now = new \DateTimeImmutable()): static
    {
        $this->collectorUrl = $collectorUrl;
        $this->instanceId = $instanceId;
        $this->encryptedInstanceSecret = $encryptedSecret;
        $this->registeredAt = $now;

        return $this;
    }

    public function forgetRegistration(): static
    {
        $this->collectorUrl = null;
        $this->instanceId = null;
        $this->encryptedInstanceSecret = null;
        $this->registeredAt = null;

        return $this;
    }
}
