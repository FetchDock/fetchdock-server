<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Resource\Admin\AdminDownloadJob;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /admin/download_jobs/{uuid}/restore: restores a soft-deleted job, and its soft-deleted files
 * that still exist on disk (files that are gone stay deleted).
 *
 * @implements ProcessorInterface<null, AdminDownloadJob>
 */
final readonly class AdminDownloadJobRestoreProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminLookup $lookup,
        private SoftDeleteFilter $softDeleteFilter,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminDownloadJob
    {
        $job = $this->lookup->job($uriVariables['uuid'] ?? null);
        if (!$job->isDeleted()) {
            throw new ConflictHttpException('The download job is not deleted.');
        }

        return $this->softDeleteFilter->disabled(function () use ($job): AdminDownloadJob {
            $job->setDeletedAt(null);
            $restoredFiles = 0;
            foreach ($job->getFiles() as $file) {
                if ($file->isDeleted() && null !== $file->getPath() && is_file($file->getPath())) {
                    $file->setDeletedAt(null);
                    ++$restoredFiles;
                }
            }
            $this->entityManager->flush();

            $this->logger->info('Download job restored by an admin', [
                'job' => $job->getUuid()?->toRfc4122(),
                'restored_files' => $restoredFiles,
                'admin' => $this->security->getUser()?->getUserIdentifier(),
            ]);

            return AdminDownloadJob::fromEntity($job);
        });
    }
}
