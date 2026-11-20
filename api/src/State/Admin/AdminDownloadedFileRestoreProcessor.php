<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Resource\Admin\AdminDownloadedFile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /admin/downloaded_files/{id}/restore: restores a soft-deleted file that still exists on disk.
 *
 * @implements ProcessorInterface<null, AdminDownloadedFile>
 */
final readonly class AdminDownloadedFileRestoreProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminLookup $lookup,
        private SoftDeleteFilter $softDeleteFilter,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminDownloadedFile
    {
        $file = $this->lookup->file($uriVariables['id'] ?? null);
        if (!$file->isDeleted()) {
            throw new ConflictHttpException('The downloaded file is not deleted.');
        }
        if (null === $file->getPath() || !is_file($file->getPath())) {
            throw new ConflictHttpException('The downloaded file no longer exists on disk.');
        }

        return $this->softDeleteFilter->disabled(function () use ($file): AdminDownloadedFile {
            $file->setDeletedAt(null);
            $this->entityManager->flush();

            $this->logger->info('Downloaded file restored by an admin', [
                'file' => $file->getId(),
                'admin' => $this->security->getUser()?->getUserIdentifier(),
            ]);

            return AdminDownloadedFile::fromEntity($file);
        });
    }
}
