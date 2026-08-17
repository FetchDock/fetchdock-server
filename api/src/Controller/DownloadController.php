<?php

namespace App\Controller;

use App\Entity\DownloadedFile;
use App\Repository\DownloadJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DownloadController extends AbstractController
{
    public function __construct(
        private readonly DownloadJobRepository $downloadJobRepository,
        private readonly LoggerInterface $logger,
    ) {
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
        int $fileId,
    ): JsonResponse|BinaryFileResponse {
        $downloadJob = $this->downloadJobRepository->findOneByUuidAndToken($downloadJobUuid, $token);
        if (!$downloadJob) {
            throw new NotFoundHttpException('Download job not found or invalid token.');
        }

        /** @var DownloadedFile $file */
        $file = $downloadJob->getFiles()->filter(fn (DownloadedFile $f) => $f->getId() === $fileId)->first();
        if (!$file) {
            throw new NotFoundHttpException('File not found in this download job.');
        }

        // Check if file exists on disk
        if (!file_exists($file->getPath() ?: '')) {
            $this->logger->error('File not found', [
                'file' => $file->getPath(),
                'downloadJob' => $downloadJob
            ]);
            throw new NotFoundHttpException('File not found on server.');
        }

        // Get the filename from the metadata

        switch ($downloadJob->getDownloader()) {
            case 'yt-dlp-cli':
                $metadata = $file->getMetadata();
                if (isset($metadata['title'], $metadata['ext']) && $metadata['title'] !== '' && $metadata['ext'] !== '') {
                    $filename = $metadata['title'].'.'.$metadata['ext'];
                } else {
                    $filename = basename($file->getPath() ?: 'downloaded_file');
                }
                break;
            case 'gallery-dl-cli':
                $metadata = $file->getMetadata();
                $filename = $metadata['filename'] ?? basename($file->getPath() ?: 'downloaded_file');
                break;
            default:
                $filename = basename($file->getPath() ?: 'downloaded_file');
                break;
        }

        // By this point we're ready to serve the file
        // For slow clients, this might take a while
        // So we increase the time limit for this script
        // Note: This is not ideal for very large files or high traffic
        // but should be fine for most use cases
        // For now let's set it to 3 hours
        set_time_limit(10800); // 3 hours

        return $this->file(
            file: $file->getPath(),
            fileName: $filename
        );
    }
}
