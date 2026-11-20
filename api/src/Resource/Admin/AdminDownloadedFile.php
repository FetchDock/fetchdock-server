<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\Admin\AdminJobReferenceDTO;
use App\Dto\DownloadedFileOutput;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\State\Admin\AdminDownloadedFileProvider;
use App\State\Admin\AdminDownloadedFileRestoreProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Any downloaded file, including soft-deleted and hidden ones, for admins.
 */
#[ApiResource(
    shortName: 'AdminDownloadedFile',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/downloaded_files',
            description: 'All downloaded files, including soft-deleted and hidden ones, newest first.',
            parameters: [
                'deleted' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    description: 'true: only soft-deleted files; false: only live files; omitted: both',
                    constraints: [new Assert\Choice(choices: ['true', 'false', '1', '0'])],
                ),
                'orphaned' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    description: 'true: only files no live (non-deleted) job uses; false: only files a live job uses; omitted: both',
                    constraints: [new Assert\Choice(choices: ['true', 'false', '1', '0'])],
                ),
            ],
        ),
        new GetCollection(
            uriTemplate: '/admin/download_jobs/{uuid}/files',
            uriVariables: ['uuid' => new Link(fromClass: AdminDownloadJob::class, identifiers: ['uuid'])],
            description: "A job's files, including soft-deleted and hidden ones.",
        ),
        new Get(uriTemplate: '/admin/downloaded_files/{id}'),
        new Post(
            uriTemplate: '/admin/downloaded_files/{id}/restore',
            status: 200,
            description: 'Restores a soft-deleted file. 409 if the file is not deleted or no longer exists on disk.',
            security: "is_granted('ROLE_MANAGE_ALL_DOWNLOADS')",
            input: false,
            read: false,
            deserialize: false,
            processor: AdminDownloadedFileRestoreProcessor::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_VIEW_ALL_DOWNLOADS')",
    provider: AdminDownloadedFileProvider::class,
)]
final class AdminDownloadedFile
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public ?string $filename = null;

    /** Absolute path on the server */
    public ?string $path = null;

    /** Whether the file still exists at its path */
    public bool $existsOnDisk = false;

    /** Hidden files (e.g. metadata files) are not listed to users */
    public bool $visible = false;

    /**
     * Descriptive metadata (see DownloadedFileOutput::METADATA_KEYS).
     *
     * @var array<string, scalar>
     */
    public array $metadata = [];

    /** Set once no live job uses the file anymore, or by an admin */
    public ?\DateTimeInterface $deletedAt = null;

    /**
     * All jobs linked to the file, including soft-deleted ones.
     *
     * @var list<AdminJobReferenceDTO>
     */
    public array $jobs = [];

    /** Download URI through a live linked job, null if every linked job is deleted */
    public ?string $downloadUri = null;

    public static function fromEntity(DownloadedFile $file, ?DownloadJob $preferredJob = null): self
    {
        $jobs = $file->getDownloadJob()->toArray();
        $liveJobs = array_values(array_filter($jobs, static fn (DownloadJob $job): bool => !$job->isDeleted()));
        $downloadJob = null !== $preferredJob && !$preferredJob->isDeleted() ? $preferredJob : ($liveJobs[0] ?? null);
        $userOutput = null !== $downloadJob ? DownloadedFileOutput::forJob($file, $downloadJob) : null;

        $output = new self();
        $output->id = (int) $file->getId();
        $output->filename = $file->getFilename();
        $output->path = $file->getPath();
        $output->existsOnDisk = null !== $file->getPath() && is_file($file->getPath());
        $output->visible = (bool) $file->isVisible();
        $output->metadata = DownloadedFileOutput::filterMetadata($file->getMetadata());
        $output->deletedAt = $file->getDeletedAt();
        $output->jobs = array_values(array_map(AdminJobReferenceDTO::fromEntity(...), $jobs));
        $output->downloadUri = $userOutput?->downloadUri;

        return $output;
    }
}
