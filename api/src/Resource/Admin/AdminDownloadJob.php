<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\Admin\AdminOwnerDTO;
use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;
use App\State\Admin\AdminDownloadJobDeleteProcessor;
use App\State\Admin\AdminDownloadJobProvider;
use App\State\Admin\AdminDownloadJobRestoreProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Any user's download job, including soft-deleted ones, for admins.
 */
#[ApiResource(
    shortName: 'AdminDownloadJob',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/download_jobs',
            description: "All users' download jobs, including soft-deleted ones, newest first.",
            parameters: [
                'owner' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: "Only the jobs of this user (the owner's uuid)",
                    constraints: [new Assert\Uuid()],
                ),
                'state' => new QueryParameter(
                    schema: ['type' => 'integer', 'enum' => [0, 1, 2, 3, 4, 5]],
                    description: 'Only jobs in this state (see DownloadStateEnum)',
                    constraints: [new Assert\Choice(choices: ['0', '1', '2', '3', '4', '5'])],
                ),
                'deleted' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    description: 'true: only soft-deleted jobs; false: only live jobs; omitted: both',
                    constraints: [new Assert\Choice(choices: ['true', 'false', '1', '0'])],
                ),
                'uri' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 2048],
                    description: 'Only jobs whose URI contains this text',
                    constraints: [new Assert\Length(max: 2048)],
                ),
            ],
        ),
        new Get(uriTemplate: '/admin/download_jobs/{uuid}'),
        new Post(
            uriTemplate: '/admin/download_jobs/{uuid}/restore',
            status: 200,
            description: 'Restores a soft-deleted job, and its soft-deleted files that still exist on disk. 409 if the job is not deleted.',
            security: "is_granted('ROLE_MANAGE_ALL_DOWNLOADS')",
            input: false,
            read: false,
            deserialize: false,
            processor: AdminDownloadJobRestoreProcessor::class,
        ),
        new Delete(
            uriTemplate: '/admin/download_jobs/{uuid}',
            description: 'Permanently deletes a soft-deleted job and its events. Its files are kept (soft-deleted if no other live job uses them). 409 if the job is not deleted.',
            security: "is_granted('ROLE_MANAGE_ALL_DOWNLOADS')",
            processor: AdminDownloadJobDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_VIEW_ALL_DOWNLOADS')",
    provider: AdminDownloadJobProvider::class,
)]
final class AdminDownloadJob
{
    #[ApiProperty(identifier: true)]
    public string $uuid;

    public string $uri;

    public ?DownloadStateEnum $state = null;

    public ?string $downloader = null;

    /** Null for jobs created before download jobs had owners */
    public ?AdminOwnerDTO $owner = null;

    public ?\DateTimeInterface $createdAt = null;

    public ?\DateTimeInterface $updatedAt = null;

    /** Set when the owner deleted the job */
    public ?\DateTimeInterface $deletedAt = null;

    /** Number of files linked to the job, including soft-deleted and hidden ones (see /admin/download_jobs/{uuid}/files) */
    public int $fileCount = 0;

    /**
     * @param int|null $fileCount the number of linked files, if already counted (else the collection is loaded)
     */
    public static function fromEntity(DownloadJob $job, ?int $fileCount = null): self
    {
        $output = new self();
        $output->uuid = (string) $job->getUuid()?->toRfc4122();
        $output->uri = (string) $job->getUri();
        $output->state = $job->getState();
        $output->downloader = $job->getDownloader();
        $output->owner = AdminOwnerDTO::fromEntity($job->getOwner());
        $output->createdAt = $job->getCreatedAt();
        $output->updatedAt = $job->getUpdatedAt();
        $output->deletedAt = $job->getDeletedAt();
        $output->fileCount = $fileCount ?? $job->getFiles()->count();

        return $output;
    }
}
