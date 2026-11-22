<?php

namespace App\Entity;

use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicySettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The instance's privacy policy settings (ADR 0013), a single row with the id ID: the operator's answers, and what
 * the server observed that the policy depends on.
 */
#[ORM\Entity(repositoryClass: PrivacyPolicySettingsRepository::class)]
class PrivacyPolicySettings
{
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    /** The operator's answers, normalized by PrivacyPolicyAnswers */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answersUpdatedAt = null;

    /**
     * When a user who may view all downloads without being an administrator last signed in. Roles come from the
     * identity provider with each request, so the server can't list who holds them (ViewAllRoleListener).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $viewAllRoleSeenAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getAnswers(): PrivacyPolicyAnswers
    {
        return PrivacyPolicyAnswers::fromArray($this->answers);
    }

    public function setAnswers(PrivacyPolicyAnswers $answers, \DateTimeImmutable $now = new \DateTimeImmutable()): static
    {
        $this->answers = $answers->toArray();
        $this->answersUpdatedAt = $now;

        return $this;
    }

    public function getAnswersUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->answersUpdatedAt;
    }

    public function getViewAllRoleSeenAt(): ?\DateTimeImmutable
    {
        return $this->viewAllRoleSeenAt;
    }

    public function setViewAllRoleSeenAt(?\DateTimeImmutable $viewAllRoleSeenAt): static
    {
        $this->viewAllRoleSeenAt = $viewAllRoleSeenAt;

        return $this;
    }
}
