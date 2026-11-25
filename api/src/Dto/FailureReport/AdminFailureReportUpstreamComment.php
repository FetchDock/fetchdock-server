<?php

namespace App\Dto\FailureReport;

use App\Entity\FailureReportUpstreamComment;
use App\Enum\UpstreamCommentState;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A comment an admin sent to the FetchDock developers about a forwarded report.
 */
final class AdminFailureReportUpstreamComment
{
    #[Groups(['admin_failure_report:read'])]
    public string $comment = '';

    #[Groups(['admin_failure_report:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    #[Groups(['admin_failure_report:read'])]
    public UpstreamCommentState $state = UpstreamCommentState::PENDING;

    /** Why the collector refused it */
    #[Groups(['admin_failure_report:read'])]
    public ?string $error = null;

    public static function fromEntity(FailureReportUpstreamComment $entity): self
    {
        $comment = new self();
        $comment->comment = $entity->getComment();
        $comment->createdAt = $entity->getCreatedAt();
        $comment->state = $entity->getState();
        $comment->error = $entity->getError();

        return $comment;
    }
}
