<?php

namespace App\Dto\Admin;

use App\Resource\Admin\AdminDownloadDirectory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /admin/download_directories/{id}/file-moves.
 */
final class DirectoryFileMoveRequest
{
    /** The directory the files move to */
    #[Assert\NotNull]
    public ?AdminDownloadDirectory $targetDirectory = null;

    /** Switch the sites that use the source directory to the target once the batch is planned */
    public bool $reassignSites = true;
}
