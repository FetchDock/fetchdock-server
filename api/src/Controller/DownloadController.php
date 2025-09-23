<?php

namespace App\Controller;

use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Repository\DownloadedFileRepository;
use App\Repository\DownloadJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DownloadController extends AbstractController
{
    public function __construct(
        private DownloadJobRepository $downloadJobRepository,
        private DownloadedFileRepository $downloadedFileRepository
    )
    {
    }

    #[Route(
        '/download/{downloadJobUuid}/{token}/files/{fileId}',
        name: 'app_download',
        requirements: ['downloadJobUuid' => '[0-9a-fA-F\-]{36}', 'token' => '[0-9a-fA-F]{64}', 'fileId' => '\d+'],
        methods: ['GET']
    )]
    public function index(
        string $downloadJobUuid,
        string $token,
        int $fileId
    ): JsonResponse|BinaryFileResponse
    {
        $downloadJob = $this->downloadJobRepository->findOneByUuidAndToken($downloadJobUuid, $token);
        if (!$downloadJob) {
            throw new NotFoundHttpException('Download job not found or invalid token.');
        }

        /** @var DownloadedFile $file */
        $file = $downloadJob->getFiles()->filter(fn(DownloadedFile $f) => $f->getId() === $fileId)->first();
        if (!$file) {
            throw new NotFoundHttpException('File not found in this download job.');
        }

        // Check if file exists on disk
        if (!file_exists($file->getPath() ?: '')) {
            throw new NotFoundHttpException('File not found on server.');
        }

        // Get the filename from the metadata

        switch ($downloadJob->getDownloader()) {
            case 'yt-dlp-cli':
                $metadata = $file->getMetadata();
                $filename = $metadata['title'].'.'.$metadata['ext'] ?? basename($file->getPath() ?: 'downloaded_file');
                break;
            case 'gallery-dl-cli':
                $metadata = $file->getMetadata();
                $filename = $metadata['filename'] ?? basename($file->getPath() ?: 'downloaded_file');
                break;
            default:
                $filename = basename($file->getPath() ?: 'downloaded_file');
                break;
        }

        return $this->file(
            file: $file->getPath(),
            fileName: $filename
        );
    }
}
