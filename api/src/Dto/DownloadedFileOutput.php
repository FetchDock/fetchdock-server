<?php

namespace App\Dto;

use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;

/**
 * A downloaded file as its job's owner sees it.
 *
 * Files can be shared by jobs of different owners (a download is reused for the same URI), so the
 * output is always built for one job: the download URI carries that job's token, never another job's.
 * The server path and the raw downloader metadata (which may contain paths, request headers and
 * cookies) are never part of it.
 */
final readonly class DownloadedFileOutput
{
    /**
     * Descriptive metadata keys that may be shown to the job's owner.
     */
    public const array METADATA_KEYS = [
        'id', 'title', 'ext', 'duration', 'width', 'height', 'filesize', 'uploader', 'upload_date',
        'thumbnail', 'webpage_url', 'extractor_key', 'filename', 'extension',
    ];

    public function __construct(
        public int $id,
        public ?string $filename,
        public bool $visible,
        /** @var array<string, scalar> */
        public array $metadata,
        public string $downloadUri,
    ) {
    }

    public static function forJob(DownloadedFile $file, DownloadJob $job): self
    {
        $metadata = array_filter(
            array_intersect_key($file->getMetadata(), array_flip(self::METADATA_KEYS)),
            static fn (mixed $value): bool => is_scalar($value),
        );

        return new self(
            id: $file->getId(),
            filename: $file->getFilename(),
            visible: (bool) $file->isVisible(),
            metadata: $metadata,
            downloadUri: '/download/'.$job->getUuid()->toRfc4122().'/'.$job->getToken().'/files/'.$file->getId(),
        );
    }
}
