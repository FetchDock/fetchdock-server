<?php

namespace App\Entity;

use App\Enum\FileMoveItemStatus;
use App\Repository\FileMoveItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file of a FileMoveBatch, with the location it is moved from (resumable and auditable per file).
 * The migration adds a partial unique index on file_id for pending and moving items: a file is in one active move at most.
 */
#[ORM\Entity(repositoryClass: FileMoveItemRepository::class)]
#[ORM\Index(name: 'idx_file_move_item_batch_status', columns: ['batch_id', 'status'])]
#[ORM\Index(name: 'idx_file_move_item_source', columns: ['source_directory_id', 'source_relative_path'])]
// status IN ('pending', 'moving'), spelled as PostgreSQL returns it, so doctrine:schema:validate sees no difference
#[ORM\UniqueConstraint(name: 'uniq_file_move_item_active_file', columns: ['file_id'], options: ['where' => "((status)::text = ANY ((ARRAY['pending'::character varying, 'moving'::character varying])::text[]))"])]
class FileMoveItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FileMoveBatch $batch;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadedFile $file;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DownloadDirectory $sourceDirectory = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceRelativePath;

    #[ORM\Column(length: 16, enumType: FileMoveItemStatus::class)]
    private FileMoveItemStatus $status = FileMoveItemStatus::PENDING;

    /** Size of the source, measured when the batch starts */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $size = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(FileMoveBatch $batch, DownloadedFile $file, ?DownloadDirectory $sourceDirectory, string $sourceRelativePath)
    {
        $this->batch = $batch;
        $this->file = $file;
        $this->sourceDirectory = $sourceDirectory;
        $this->sourceRelativePath = $sourceRelativePath;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): FileMoveBatch
    {
        return $this->batch;
    }

    public function getFile(): DownloadedFile
    {
        return $this->file;
    }

    public function getSourceDirectory(): ?DownloadDirectory
    {
        return $this->sourceDirectory;
    }

    public function getSourceRelativePath(): string
    {
        return $this->sourceRelativePath;
    }

    public function getStatus(): FileMoveItemStatus
    {
        return $this->status;
    }

    public function setStatus(FileMoveItemStatus $status, ?string $error = null): static
    {
        $this->status = $status;
        $this->error = $error;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function startAttempt(): static
    {
        ++$this->attempts;

        return $this->setStatus(FileMoveItemStatus::MOVING);
    }
}
