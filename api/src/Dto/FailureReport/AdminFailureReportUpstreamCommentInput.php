<?php

namespace App\Dto\FailureReport;

use App\Entity\FailureReportUpstreamComment;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /admin/failure_reports/{id}/upstream_comments.
 */
final class AdminFailureReportUpstreamCommentInput
{
    /** Sent to the FetchDock developers as written */
    #[Assert\NotBlank]
    #[Assert\Length(max: FailureReportUpstreamComment::MAX_LENGTH)]
    #[Groups(['admin_failure_report:upstream_comment'])]
    public string $comment = '';
}
