<?php

namespace App\Entity;

use App\Enum\PrivacyPolicyRevisionReason;
use App\Repository\PrivacyPolicyRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One published state of the generated privacy policy (ADR 0013). Revisions are never changed or deleted, so it
 * stays clear which text applied when. PrivacyPolicyRevisions creates a new one whenever an input changes.
 */
#[ORM\Entity(repositoryClass: PrivacyPolicyRevisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_privacy_policy_revision_number', columns: ['number'])]
class PrivacyPolicyRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** 1, 2, 3, ... shown on the page */
        #[ORM\Column]
        private int $number,
        #[ORM\Column(length: 16, enumType: PrivacyPolicyRevisionReason::class)]
        private PrivacyPolicyRevisionReason $reason,
        /** The policy text version (privacy/policy-version.yaml) */
        #[ORM\Column(length: 16)]
        private string $textVersion,
        #[ORM\Column(length: 64)]
        private string $appVersion,
        /** sha256 over all inputs; a revision is created when it differs from the latest one */
        #[ORM\Column(length: 64)]
        private string $inputsHash,
        #[ORM\Column(length: 64)]
        private string $answersHash,
        #[ORM\Column(length: 64)]
        private string $configurationHash,
        #[ORM\Column(type: Types::TEXT)]
        private string $markdown,
        /** The Markdown rendered when the revision was created, so later renderer changes don't alter it */
        #[ORM\Column(type: Types::TEXT)]
        private string $html,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->appVersion = mb_substr($appVersion, 0, 64);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getReason(): PrivacyPolicyRevisionReason
    {
        return $this->reason;
    }

    public function getTextVersion(): string
    {
        return $this->textVersion;
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    public function getInputsHash(): string
    {
        return $this->inputsHash;
    }

    public function getAnswersHash(): string
    {
        return $this->answersHash;
    }

    public function getConfigurationHash(): string
    {
        return $this->configurationHash;
    }

    public function getMarkdown(): string
    {
        return $this->markdown;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
