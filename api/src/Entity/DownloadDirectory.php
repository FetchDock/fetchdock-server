<?php

namespace App\Entity;

use App\Repository\DownloadDirectoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin-managed storage location for downloaded files (see ADR 0011).
 * Its path is absolute and must resolve inside one of the DOWNLOAD_ROOTS.
 */
#[ORM\Entity(repositoryClass: DownloadDirectoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_download_directory_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_download_directory_path', columns: ['path'])]
// Exactly one default directory: at most one through this index, at least one through the seed and the admin API
#[ORM\UniqueConstraint(name: 'uniq_download_directory_default', columns: ['is_default'], options: ['where' => 'is_default'])]
class DownloadDirectory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $path;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    /** Verify cross-device copies into this directory with SHA-256 in addition to the size */
    #[ORM\Column(options: ['default' => false])]
    private bool $verifyChecksum = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $path, bool $isDefault = false)
    {
        $this->name = $name;
        $this->setPath($path);
        $this->isDefault = $isDefault;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** Absolute path, without a trailing slash */
    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = '/' === $path ? $path : rtrim($path, '/');

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isVerifyChecksum(): bool
    {
        return $this->verifyChecksum;
    }

    public function setVerifyChecksum(bool $verifyChecksum): static
    {
        $this->verifyChecksum = $verifyChecksum;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The absolute path of a file stored at the given path relative to this directory */
    public function resolve(string $relativePath): string
    {
        // Legacy rows the backfill couldn't map keep their absolute path (see app:files:verify-locations)
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        return ('/' === $this->path ? '' : $this->path).'/'.$relativePath;
    }
}
