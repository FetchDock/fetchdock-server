<?php

namespace App\Entity;

use App\Enum\FileMoveBatchStatus;
use App\Enum\FileMoveScope;
use App\Repository\FileMoveBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tracked move of files to another download directory (ADR 0011). Each file is a FileMoveItem, moved by its own
 * message; the counters are updated atomically in SQL by the workers (see FileMoveBatchRepository).
 */
#[ORM\Entity(repositoryClass: FileMoveBatchRepository::class)]
#[ORM\Index(name: 'idx_file_move_batch_status', columns: ['status'])]
class FileMoveBatch
{
    public const int MAX_ERRORS = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: FileMoveScope::class)]
    private FileMoveScope $scope;

    /** The site whose files are moved (site scope) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Site $site = null;

    /** The directory whose files are moved (directory scope) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DownloadDirectory $sourceDirectory = null;

    /** Null only once the directory was deleted after the batch */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DownloadDirectory $targetDirectory = null;

    #[ORM\Column(length: 32, enumType: FileMoveBatchStatus::class)]
    private FileMoveBatchStatus $status = FileMoveBatchStatus::QUEUED;

    /** Items, skipped ones included */
    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column]
    private int $moved = 0;

    #[ORM\Column]
    private int $skipped = 0;

    #[ORM\Column]
    private int $failed = 0;

    // int, as DBAL hydrates BIGINT: a string property would differ from its snapshot and be written back on every flush
    #[ORM\Column(type: Types::BIGINT)]
    private int $bytesTotal = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $bytesMoved = 0;

    /**
     * The first errors, at most MAX_ERRORS: {fileId, path, error}.
     *
     * @var list<array{fileId: int, path: string, error: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $errors = [];

    /** User identifier of the admin who started the batch */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $requestedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Last Mercure progress update, to throttle them across workers */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $progressPublishedAt = null;

    public function __construct(FileMoveScope $scope, DownloadDirectory $targetDirectory, ?string $requestedBy)
    {
        $this->scope = $scope;
        $this->targetDirectory = $targetDirectory;
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): FileMoveScope
    {
        return $this->scope;
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

    public function getSourceDirectory(): ?DownloadDirectory
    {
        return $this->sourceDirectory;
    }

    public function setSourceDirectory(?DownloadDirectory $sourceDirectory): static
    {
        $this->sourceDirectory = $sourceDirectory;

        return $this;
    }

    public function getTargetDirectory(): ?DownloadDirectory
    {
        return $this->targetDirectory;
    }

    public function getStatus(): FileMoveBatchStatus
    {
        return $this->status;
    }

    public function setStatus(FileMoveBatchStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getMoved(): int
    {
        return $this->moved;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function getBytesTotal(): int
    {
        return $this->bytesTotal;
    }

    public function getBytesMoved(): int
    {
        return $this->bytesMoved;
    }

    /** @return list<array{fileId: int, path: string, error: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
