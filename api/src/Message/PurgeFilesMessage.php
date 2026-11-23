<?php

namespace App\Message;

/**
 * Deletes files that no download job uses anymore, from the database and from disk (server#129).
 *
 * Files are given by DownloadedFile id, or by the absolute path a download tool reported for a job that was deleted
 * while it ran. A file still linked to any job is kept. A path without a DownloadedFile is only deleted when it changed
 * at or after $writtenSince (a Unix timestamp), so a file that was there before the job is never removed.
 */
final readonly class PurgeFilesMessage
{
    /**
     * @param list<int>    $fileIds
     * @param list<string> $paths
     */
    public function __construct(
        public array $fileIds = [],
        public array $paths = [],
        public ?int $writtenSince = null,
    ) {
    }
}
