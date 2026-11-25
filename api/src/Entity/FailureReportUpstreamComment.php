<?php

namespace App\Entity;

use App\Enum\UpstreamCommentState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An admin's comment for the FetchDock developers on a report that was already forwarded (phase 2 of ADR 0012). It is
 * sent to the collector as written; internal notes (FailureReport::adminNote) never are.
 */
#[ORM\Entity]
class FailureReportUpstreamComment
{
    public const int MAX_LENGTH = 4000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'upstreamComments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FailureReport $report;

    #[ORM\Column(type: Types::TEXT)]
    private string $comment;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 16, enumType: UpstreamCommentState::class)]
    private UpstreamCommentState $state = UpstreamCommentState::PENDING;

    /** The comment's id at the collector */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $upstreamId = null;

    /** Why the collector refused it */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(FailureReport $report, string $comment, \DateTimeImmutable $createdAt = new \DateTimeImmutable())
    {
        $this->id = Uuid::v7();
        $this->report = $report;
        $this->comment = $comment;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReport(): FailureReport
    {
        return $this->report;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getState(): UpstreamCommentState
    {
        return $this->state;
    }

    public function getUpstreamId(): ?Uuid
    {
        return $this->upstreamId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function markForwarded(Uuid $upstreamId): static
    {
        $this->state = UpstreamCommentState::FORWARDED;
        $this->upstreamId = $upstreamId;
        $this->error = null;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->state = UpstreamCommentState::FAILED;
        $this->error = $error;

        return $this;
    }
}
