<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\Admin\DirectoryFileMoveRequest;
use App\Entity\FileMoveBatch;
use App\Enum\FileMoveBatchStatus;
use App\Enum\FileMoveScope;
use App\State\Admin\AdminFileMoveBatchProcessor;
use App\State\Admin\AdminFileMoveBatchProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A tracked move of files to another download directory (ADR 0011). Progress is published privately on the batch's
 * absolute IRI (AdminTopics), at most once per second.
 */
#[ApiResource(
    shortName: 'AdminFileMoveBatch',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/file_moves',
            description: 'File move batches, newest first.',
            parameters: [
                'site' => new QueryParameter(schema: ['type' => 'string'], description: 'Only batches of this site (slug)'),
                'status' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['queued', 'running', 'completed', 'completed_with_errors', 'cancelled', 'failed']],
                    constraints: [new Assert\Choice(callback: [FileMoveBatchStatus::class, 'values'])],
                ),
            ],
        ),
        new Get(uriTemplate: '/admin/file_moves/{id}'),
        new Post(
            uriTemplate: '/admin/file_moves/{id}/cancel',
            status: 200,
            description: 'Cancels an active batch: files being moved finish, the others stay where they are. 409 if the batch is not active.',
            input: false,
            read: false,
            deserialize: false,
            name: 'cancel_file_move',
        ),
        new Post(
            uriTemplate: '/admin/sites/{slug}/file-moves',
            uriVariables: ['slug' => new Link(fromClass: AdminSiteStorage::class, identifiers: ['slug'])],
            status: 202,
            description: "Moves the site's files that aren't in its directory there (also a way to retry). 409 if a batch of the site is active, 422 if the directory isn't mounted.",
            input: false,
            read: false,
            deserialize: false,
            name: 'move_site_files',
        ),
        new Post(
            uriTemplate: '/admin/download_directories/{id}/file-moves',
            uriVariables: ['id' => new Link(fromClass: AdminDownloadDirectory::class, identifiers: ['id'])],
            status: 202,
            description: 'Moves every file of the directory to the target, whatever its site; with reassignSites, the sites using the directory switch to the target. 409 if a batch touching either directory is active, 422 if the target is the same directory or not mounted.',
            input: DirectoryFileMoveRequest::class,
            read: false,
            name: 'move_directory_files',
        ),
    ],
    normalizationContext: ['groups' => ['file_move:read'], 'skip_null_values' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminFileMoveBatchProvider::class,
    processor: AdminFileMoveBatchProcessor::class,
)]
final class AdminFileMoveBatch
{
    #[ApiProperty(identifier: true)]
    #[Groups(['file_move:read'])]
    public int $id;

    #[Groups(['file_move:read'])]
    public FileMoveScope $scope;

    /** The site's slug (site batches) */
    #[Groups(['file_move:read'])]
    public ?string $site = null;

    /** Directory batches: the directory the files are moved from */
    #[Groups(['file_move:read'])]
    public ?AdminDownloadDirectory $sourceDirectory = null;

    /** Null only if the directory was deleted since */
    #[Groups(['file_move:read'])]
    public ?AdminDownloadDirectory $targetDirectory = null;

    #[Groups(['file_move:read'])]
    public FileMoveBatchStatus $status;

    /** Files in the batch, skipped ones included */
    #[Groups(['file_move:read'])]
    public int $total = 0;

    #[Groups(['file_move:read'])]
    public int $moved = 0;

    /** Not moved on purpose: shared with another site, job in progress, in another batch, already at target, cancelled */
    #[Groups(['file_move:read'])]
    public int $skipped = 0;

    #[Groups(['file_move:read'])]
    public int $failed = 0;

    #[Groups(['file_move:read'])]
    public int $bytesTotal = 0;

    #[Groups(['file_move:read'])]
    public int $bytesMoved = 0;

    /**
     * The first errors (at most 50).
     *
     * @var list<array{fileId: int, path: string, error: string}>
     */
    #[ApiProperty(openapiContext: ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['fileId' => ['type' => 'integer'], 'path' => ['type' => 'string'], 'error' => ['type' => 'string']]]])]
    #[Groups(['file_move:read'])]
    public array $errors = [];

    #[Groups(['file_move:read'])]
    public \DateTimeImmutable $createdAt;

    #[Groups(['file_move:read'])]
    public ?\DateTimeImmutable $startedAt = null;

    #[Groups(['file_move:read'])]
    public ?\DateTimeImmutable $finishedAt = null;

    /** The admin who started the batch */
    #[Groups(['file_move:read'])]
    public ?string $requestedBy = null;

    public static function fromEntity(FileMoveBatch $batch): self
    {
        $resource = new self();
        $resource->id = (int) $batch->getId();
        $resource->scope = $batch->getScope();
        $resource->site = $batch->getSite()?->getSlug();
        $resource->sourceDirectory = null === $batch->getSourceDirectory() ? null : AdminDownloadDirectory::fromEntity($batch->getSourceDirectory());
        $resource->targetDirectory = null === $batch->getTargetDirectory() ? null : AdminDownloadDirectory::fromEntity($batch->getTargetDirectory());
        $resource->status = $batch->getStatus();
        $resource->total = $batch->getTotal();
        $resource->moved = $batch->getMoved();
        $resource->skipped = $batch->getSkipped();
        $resource->failed = $batch->getFailed();
        $resource->bytesTotal = $batch->getBytesTotal();
        $resource->bytesMoved = $batch->getBytesMoved();
        $resource->errors = $batch->getErrors();
        $resource->createdAt = $batch->getCreatedAt();
        $resource->startedAt = $batch->getStartedAt();
        $resource->finishedAt = $batch->getFinishedAt();
        $resource->requestedBy = $batch->getRequestedBy();

        return $resource;
    }
}
