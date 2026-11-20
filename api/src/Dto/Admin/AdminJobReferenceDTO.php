<?php

namespace App\Dto\Admin;

use App\Entity\DownloadJob;
use App\Enum\DownloadStateEnum;

/**
 * A download job linked to a file, as shown to admins (details: /admin/download_jobs/{uuid}).
 */
final readonly class AdminJobReferenceDTO
{
    public function __construct(
        public string $uuid,
        public ?AdminOwnerDTO $owner,
        public ?DownloadStateEnum $state,
        public ?\DateTimeInterface $deletedAt,
    ) {
    }

    public static function fromEntity(DownloadJob $job): self
    {
        return new self((string) $job->getUuid()?->toRfc4122(), AdminOwnerDTO::fromEntity($job->getOwner()), $job->getState(), $job->getDeletedAt());
    }
}
