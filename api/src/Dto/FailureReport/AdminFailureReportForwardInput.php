<?php

namespace App\Dto\FailureReport;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /admin/failure_reports/{id}/forward.
 */
final class AdminFailureReportForwardInput
{
    /** A comment for the FetchDock developers, sent with the report as written (debugging context, how often it happens here) */
    #[Assert\Length(max: 4000)]
    #[Groups(['admin_failure_report:forward'])]
    public ?string $upstreamComment = null;
}
