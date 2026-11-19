<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Soft-deletes a DownloadJob for its owner, and soft-deletes its files that no other live job uses anymore.
 *
 * The links between the job and its files are kept on purpose: they record which files the job had (for admins),
 * and allow restoring a soft-deleted file when its URI is submitted again. Files are never removed from disk here.
 *
 * @implements ProcessorInterface<DownloadJob, void>
 */
class DownloadJobDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param DownloadJob $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        // The SoftDeleteable listener turns these removals into setting deletedAt
        $this->entityManager->remove($data);

        $softDeletedFiles = 0;
        foreach ($data->getFiles() as $file) {
            if (!$this->isUsedByAnotherLiveJob($file, $data)) {
                $this->entityManager->remove($file);
                ++$softDeletedFiles;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Download job deleted by its owner', [
            'job_uuid' => $data->getUuid()?->toRfc4122(),
            'files' => $data->getFiles()->count(),
            'soft_deleted_files' => $softDeletedFiles,
        ]);
    }

    private function isUsedByAnotherLiveJob(DownloadedFile $file, DownloadJob $deletedJob): bool
    {
        foreach ($file->getDownloadJob() as $job) {
            if ($job !== $deletedJob && !$job->isDeleted()) {
                return true;
            }
        }

        return false;
    }
}
