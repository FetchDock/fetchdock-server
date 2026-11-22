<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\DownloadDirectory;
use App\Service\Storage\DownloadTargets;
use App\State\Admin\AdminDownloadDirectoryProcessor;
use App\State\Admin\AdminDownloadDirectoryProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A storage location for downloaded files (ADR 0011). Its path must be inside DOWNLOAD_ROOTS, exist, be writable
 * and not overlap another directory. The api and the worker need the same mounts.
 */
#[ApiResource(
    shortName: 'AdminDownloadDirectory',
    operations: [
        new GetCollection(uriTemplate: '/admin/download_directories', paginationEnabled: false, description: 'All download directories, the default one first.'),
        new Get(uriTemplate: '/admin/download_directories/{id}'),
        new Post(
            uriTemplate: '/admin/download_directories',
            description: 'Adds a directory. 422 if its path is outside DOWNLOAD_ROOTS, missing, not writable or overlaps another directory.',
        ),
        new Patch(
            uriTemplate: '/admin/download_directories/{id}',
            description: 'The path can change while the directory holds no files, or with relocate: true once the whole tree was moved outside FetchDock (only the record changes). isDefault: true makes it the default directory.',
        ),
        new Delete(
            uriTemplate: '/admin/download_directories/{id}',
            description: '409 for the default directory, and while files or sites use the directory.',
        ),
    ],
    normalizationContext: ['groups' => ['download_directory:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['download_directory:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminDownloadDirectoryProvider::class,
    processor: AdminDownloadDirectoryProcessor::class,
)]
final class AdminDownloadDirectory
{
    #[ApiProperty(identifier: true)]
    #[Groups(['download_directory:read'])]
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['download_directory:read', 'download_directory:write'])]
    public string $name = '';

    /** Absolute path, stored with symlinks resolved */
    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    #[Groups(['download_directory:read', 'download_directory:write'])]
    public string $path = '';

    /** Downloads of sites without a directory go here. Set it to true to switch the default; it can't be unset. */
    #[Groups(['download_directory:read', 'download_directory:write'])]
    public bool $isDefault = false;

    /** Verify copies into this directory (file moves across filesystems) with SHA-256 in addition to the size */
    #[Groups(['download_directory:read', 'download_directory:write'])]
    public bool $verifyChecksum = false;

    /** With a new path: the whole tree was already moved there outside FetchDock, so only the record changes */
    #[Groups(['download_directory:write'])]
    public bool $relocate = false;

    /** Files stored in the directory, soft-deleted ones included */
    #[Groups(['download_directory:read'])]
    public int $fileCount = 0;

    /** Sites whose new downloads go to the directory (not counting sites that use the default) */
    #[Groups(['download_directory:read'])]
    public int $siteCount = 0;

    /** Whether the path is mounted and writable in the api container */
    #[Groups(['download_directory:read'])]
    public bool $available = false;

    public static function fromEntity(DownloadDirectory $directory, int $fileCount = 0, int $siteCount = 0): self
    {
        $resource = new self();
        $resource->id = $directory->getId();
        $resource->name = $directory->getName();
        $resource->path = $directory->getPath();
        $resource->isDefault = $directory->isDefault();
        $resource->verifyChecksum = $directory->isVerifyChecksum();
        $resource->fileCount = $fileCount;
        $resource->siteCount = $siteCount;
        $resource->available = DownloadTargets::isAvailable($directory);

        return $resource;
    }
}
