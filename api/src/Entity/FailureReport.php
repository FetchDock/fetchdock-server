<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\FailureReportDiagnostics;
use App\Enum\FailureReportKind;
use App\Enum\FailureReportStatus;
use App\Interface\OwnerFilterableInterface;
use App\Repository\FailureReportRepository;
use App\State\FailureReportProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A user's report of a failed download or of a page FetchDock can't handle (ADR 0012).
 *
 * The client composes the report and the server stores it as sent, adding derived fields only (host, site,
 * status, timestamps). Nothing is copied from the linked job. The owner is kept for deduplication, rate
 * limiting and withdrawal; admins see reports through AdminFailureReport, which hides the owner and the job.
 */
#[ORM\Entity(repositoryClass: FailureReportRepository::class)]
#[ORM\Index(name: 'idx_failure_report_owner_canonical_key', columns: ['owner_id', 'canonical_key'])]
#[ORM\Index(name: 'idx_failure_report_created_at', columns: ['created_at'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/users/failure_reports', description: "The user's failure reports, newest first."),
        new Post(
            uriTemplate: '/users/failure_reports',
            description: 'Reports a failed download job or an unsupported URL. Stored as sent, plus the derived host and site. '
                .'409 if the user reported the same media (canonical key) with the same kind within 24 hours, '
                .'422 for invalid reports, 429 above 20 reports per hour, 403 when the server disabled failure reports.',
            processor: FailureReportProcessor::class,
        ),
        new Get(uriTemplate: '/users/failure_reports/{id}'),
        new Delete(uriTemplate: '/users/failure_reports/{id}', description: 'Withdraws a report: it is deleted for good.'),
    ],
    normalizationContext: ['groups' => ['failure_report:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['failure_report:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_USER')",
    order: ['createdAt' => 'DESC'],
)]
#[Assert\Callback('validateKind')]
class FailureReport implements OwnerFilterableInterface
{
    public const int URL_MAX_LENGTH = 2048;

    #[ApiProperty(identifier: true)]
    #[Groups(['failure_report:read'])]
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OidcSubjectIdentifier $owner = null;

    #[Assert\NotNull]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[ORM\Column(length: 32, enumType: FailureReportKind::class)]
    private ?FailureReportKind $kind = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: self::URL_MAX_LENGTH)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[ORM\Column(type: Types::TEXT)]
    private ?string $url = null;

    /** Set from $downloadJobIri by the processor: the owner's FAILED job (job_failed reports) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DownloadJob $downloadJob = null;

    /** The job IRI as sent; resolved and checked by the processor */
    #[ApiProperty(
        description: 'IRI of the failed job, e.g. /download_jobs/{uuid}. Required for job_failed reports, not allowed otherwise. Null once the job is deleted.',
        schema: ['type' => ['string', 'null'], 'format' => 'iri-reference'],
    )]
    #[Assert\Length(max: 255)]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[SerializedName('downloadJob')]
    private ?string $downloadJobIri = null;

    #[Assert\Length(max: 512)]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[Assert\Length(max: self::URL_MAX_LENGTH)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $referrer = null;

    /**
     * @var array<string, string>|null
     */
    #[ORM\Column(name: 'diagnostics', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $diagnosticsData = null;

    #[Assert\Length(max: 2000)]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Host of the URL, lowercased */
    #[Groups(['failure_report:read'])]
    #[ORM\Column(length: 255)]
    private string $host = '';

    /** The site of the URL, resolved without fetching the page */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    /** Identifies the media behind the URL (ADR 0009), for deduplication. Never serialized. */
    #[ORM\Column(length: 255)]
    private string $canonicalKey = '';

    #[Groups(['failure_report:read'])]
    #[ORM\Column(length: 32, enumType: FailureReportStatus::class)]
    private FailureReportStatus $status = FailureReportStatus::NEW;

    /** Internal note of the admins, never shown to the reporter */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[Groups(['failure_report:read'])]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function validateKind(ExecutionContextInterface $context): void
    {
        if (FailureReportKind::JOB_FAILED === $this->kind && null === $this->downloadJobIri) {
            $context->buildViolation('A job_failed report needs the failed download job.')->atPath('downloadJob')->addViolation();
        }
        if (FailureReportKind::UNSUPPORTED_URL === $this->kind) {
            if (null !== $this->downloadJobIri) {
                $context->buildViolation('Only job_failed reports link a download job.')->atPath('downloadJob')->addViolation();
            }
            if (null !== $this->diagnosticsData) {
                $context->buildViolation('Only job_failed reports carry diagnostics.')->atPath('diagnostics')->addViolation();
            }
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getKind(): ?FailureReportKind
    {
        return $this->kind;
    }

    public function setKind(FailureReportKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getDownloadJob(): ?DownloadJob
    {
        return $this->downloadJob;
    }

    public function setDownloadJob(?DownloadJob $downloadJob): static
    {
        $this->downloadJob = $downloadJob;

        return $this;
    }

    /**
     * The linked job's IRI once stored, the IRI as sent before. Null when the job was deleted.
     */
    public function getDownloadJobIri(): ?string
    {
        if (null === $this->downloadJob) {
            return $this->downloadJobIri;
        }

        try {
            $uuid = $this->downloadJob->getUuid();
        } catch (EntityNotFoundException) {
            // The job is soft-deleted, so its proxy can't be loaded
            return null;
        }

        return null === $uuid ? null : '/download_jobs/'.$uuid->toRfc4122();
    }

    public function setDownloadJobIri(?string $downloadJobIri): static
    {
        $this->downloadJobIri = $downloadJobIri;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): static
    {
        $this->referrer = $referrer;

        return $this;
    }

    #[Assert\Valid]
    #[Groups(['failure_report:read', 'failure_report:write'])]
    public function getDiagnostics(): ?FailureReportDiagnostics
    {
        return null === $this->diagnosticsData ? null : FailureReportDiagnostics::fromArray($this->diagnosticsData);
    }

    public function setDiagnostics(?FailureReportDiagnostics $diagnostics): static
    {
        $data = $diagnostics?->toArray();
        $this->diagnosticsData = [] === $data ? null : $data;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    /** Slug of the URL's site, null if unknown */
    #[Groups(['failure_report:read'])]
    #[SerializedName('site')]
    public function getSiteSlug(): ?string
    {
        return $this->site?->getSlug();
    }

    public function getCanonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function setCanonicalKey(string $canonicalKey): static
    {
        $this->canonicalKey = $canonicalKey;

        return $this;
    }

    public function getStatus(): FailureReportStatus
    {
        return $this->status;
    }

    public function setStatus(FailureReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->andWhere(sprintf('%s.owner = :owner', $rootAlias))
            ->setParameter('owner', $ownerIdentifier);
    }
}
