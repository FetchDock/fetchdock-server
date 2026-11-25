<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\FailureReport\AdminFailureReportForwardInput;
use App\Dto\FailureReport\AdminFailureReportUpstreamComment;
use App\Dto\FailureReport\AdminFailureReportUpstreamCommentInput;
use App\Dto\FailureReportDiagnostics;
use App\Entity\FailureReport;
use App\Entity\FailureReportUpstreamComment;
use App\Enum\FailureReportForwarding;
use App\Enum\FailureReportKind;
use App\Enum\FailureReportStatus;
use App\State\Admin\AdminFailureReportForwardProcessor;
use App\State\Admin\AdminFailureReportProcessor;
use App\State\Admin\AdminFailureReportProvider;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Any user's failure report, for triage by admins (ADR 0012). Never exposes the reporter or the linked job.
 */
#[ApiResource(
    shortName: 'AdminFailureReport',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/failure_reports',
            description: 'All failure reports, newest first.',
            parameters: [
                'kind' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['job_failed', 'unsupported_url']],
                    constraints: [new Assert\Choice(callback: [FailureReportKind::class, 'values'])],
                ),
                'status' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['new', 'acknowledged', 'resolved', 'wont_fix']],
                    constraints: [new Assert\Choice(callback: [FailureReportStatus::class, 'values'])],
                ),
                'site' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 64],
                    description: 'Only reports of this site (slug)',
                    constraints: [new Assert\Length(max: 64)],
                ),
                'host' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 255],
                    description: 'Only reports of this host',
                    constraints: [new Assert\Length(max: 255)],
                ),
                'forwarding' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['not_requested', 'awaiting_review', 'pending', 'forwarded', 'failed']],
                    description: 'Only reports in this forwarding state',
                    constraints: [new Assert\Choice(callback: [FailureReportForwarding::class, 'values'])],
                ),
            ],
        ),
        new Get(uriTemplate: '/admin/failure_reports/{id}', requirements: ['id' => Requirement::UUID]),
        new Patch(
            uriTemplate: '/admin/failure_reports/{id}',
            requirements: ['id' => Requirement::UUID],
            description: 'Triage: sets the status and the internal admin note, which the reporter never sees.',
        ),
        new Post(
            uriTemplate: '/admin/failure_reports/{id}/forward',
            requirements: ['id' => Requirement::UUID],
            status: 202,
            description: 'Forwards a report the reporter agreed to share (forwarding awaiting_review or failed) to the FetchDock collector, '
                .'with an optional comment for the developers that is sent as written. 409 for other reports or while forwarding is disabled.',
            input: AdminFailureReportForwardInput::class,
            denormalizationContext: ['groups' => ['admin_failure_report:forward'], 'allow_extra_attributes' => false],
            read: false,
            processor: AdminFailureReportForwardProcessor::class,
            name: 'admin_failure_report_forward',
        ),
        new Post(
            uriTemplate: '/admin/failure_reports/{id}/upstream_comments',
            requirements: ['id' => Requirement::UUID],
            status: 202,
            description: 'Adds a comment for the FetchDock developers to a forwarded report; it is sent to the collector as written. 409 if the report was not forwarded.',
            input: AdminFailureReportUpstreamCommentInput::class,
            denormalizationContext: ['groups' => ['admin_failure_report:upstream_comment'], 'allow_extra_attributes' => false],
            read: false,
            processor: AdminFailureReportForwardProcessor::class,
            name: 'admin_failure_report_upstream_comment',
        ),
    ],
    normalizationContext: ['groups' => ['admin_failure_report:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['admin_failure_report:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminFailureReportProvider::class,
    processor: AdminFailureReportProcessor::class,
)]
final class AdminFailureReport
{
    #[ApiProperty(identifier: true)]
    #[Groups(['admin_failure_report:read'])]
    public string $id = '';

    #[Groups(['admin_failure_report:read'])]
    public FailureReportKind $kind = FailureReportKind::UNSUPPORTED_URL;

    #[Groups(['admin_failure_report:read'])]
    public string $url = '';

    #[Groups(['admin_failure_report:read'])]
    public string $host = '';

    /** Slug of the URL's site, null if unknown */
    #[Groups(['admin_failure_report:read'])]
    public ?string $site = null;

    #[Groups(['admin_failure_report:read'])]
    public ?string $userAgent = null;

    #[Groups(['admin_failure_report:read'])]
    public ?string $referrer = null;

    #[Groups(['admin_failure_report:read'])]
    public ?FailureReportDiagnostics $diagnostics = null;

    /** The reporter's comment */
    #[Groups(['admin_failure_report:read'])]
    public ?string $comment = null;

    #[Assert\NotNull]
    #[Groups(['admin_failure_report:read', 'admin_failure_report:write'])]
    public FailureReportStatus $status = FailureReportStatus::NEW;

    /** Internal note, never shown to the reporter or sent anywhere */
    #[Assert\Length(max: 4000)]
    #[Groups(['admin_failure_report:read', 'admin_failure_report:write'])]
    public ?string $adminNote = null;

    #[Groups(['admin_failure_report:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    /** Whether the reporter agreed to share the report with the FetchDock developers, and where it stands */
    #[Groups(['admin_failure_report:read'])]
    public FailureReportForwarding $forwarding = FailureReportForwarding::NOT_REQUESTED;

    /** The collector policy version the reporter agreed to */
    #[Groups(['admin_failure_report:read'])]
    public ?string $upstreamPolicyVersion = null;

    /** Why forwarding is held or failed */
    #[Groups(['admin_failure_report:read'])]
    public ?string $forwardingError = null;

    /** The report's id at the collector */
    #[Groups(['admin_failure_report:read'])]
    public ?string $upstreamId = null;

    #[Groups(['admin_failure_report:read'])]
    public ?\DateTimeImmutable $forwardedAt = null;

    /** The comment for the developers sent with the report */
    #[Groups(['admin_failure_report:read'])]
    public ?string $upstreamComment = null;

    /**
     * Comments for the developers added after forwarding, oldest first.
     *
     * @var list<AdminFailureReportUpstreamComment>
     */
    #[Groups(['admin_failure_report:read'])]
    public array $upstreamComments = [];

    #[Groups(['admin_failure_report:read'])]
    public ?\DateTimeImmutable $updatedAt = null;

    public static function fromEntity(FailureReport $report): self
    {
        $resource = new self();
        $resource->id = $report->getId()->toRfc4122();
        $resource->kind = $report->getKind() ?? FailureReportKind::UNSUPPORTED_URL;
        $resource->url = (string) $report->getUrl();
        $resource->host = $report->getHost();
        $resource->site = $report->getSiteSlug();
        $resource->userAgent = $report->getUserAgent();
        $resource->referrer = $report->getReferrer();
        $resource->diagnostics = $report->getDiagnostics();
        $resource->comment = $report->getComment();
        $resource->status = $report->getStatus();
        $resource->adminNote = $report->getAdminNote();
        $resource->createdAt = $report->getCreatedAt();
        $resource->updatedAt = $report->getUpdatedAt();
        $resource->forwarding = $report->getForwarding();
        $resource->upstreamPolicyVersion = $report->getUpstreamPolicyVersion();
        $resource->forwardingError = $report->getForwardingError();
        $resource->upstreamId = $report->getUpstreamId()?->toRfc4122();
        $resource->forwardedAt = $report->getForwardedAt();
        $resource->upstreamComment = $report->getUpstreamComment();
        $resource->upstreamComments = array_values(array_map(
            static fn (FailureReportUpstreamComment $comment): AdminFailureReportUpstreamComment => AdminFailureReportUpstreamComment::fromEntity($comment),
            $report->getUpstreamComments()->toArray(),
        ));

        return $resource;
    }
}
