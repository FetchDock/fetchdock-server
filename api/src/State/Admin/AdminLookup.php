<?php

namespace App\State\Admin;

use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Repository\DownloadJobRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Looks up any user's job or file for the /admin/... operations, including soft-deleted ones (404 otherwise).
 *
 * Call it inside SoftDeleteFilter::disabled() when the result's relations are used, so they are loaded unfiltered.
 */
final readonly class AdminLookup
{
    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private DownloadedFileRepository $downloadedFileRepository,
        private SoftDeleteFilter $softDeleteFilter,
    ) {
    }

    public function job(mixed $uuid): DownloadJob
    {
        $job = is_string($uuid) && Uuid::isValid($uuid)
            ? $this->softDeleteFilter->disabled(fn (): ?DownloadJob => $this->downloadJobRepository->findOneBy(['uuid' => $uuid]))
            : null;

        return $job ?? throw new NotFoundHttpException('Download job not found.');
    }

    public function file(mixed $id): DownloadedFile
    {
        $file = is_numeric($id)
            ? $this->softDeleteFilter->disabled(fn (): ?DownloadedFile => $this->downloadedFileRepository->find((int) $id))
            : null;

        return $file ?? throw new NotFoundHttpException('Downloaded file not found.');
    }
}
