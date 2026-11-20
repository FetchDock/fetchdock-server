<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Resource\Admin\AdminDownloadJob;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DELETE /admin/download_jobs/{uuid}: permanently deletes a soft-deleted job and its events.
 *
 * Only jobs their owner already deleted can be removed, so live jobs never disappear. The job's
 * files are kept: unlinked, and soft-deleted when no other live job uses them (the file on disk is
 * never touched). DQL deletes bypass the soft-delete listener, which would only soft-delete again.
 *
 * @implements ProcessorInterface<AdminDownloadJob, null>
 */
final readonly class AdminDownloadJobDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminLookup $lookup,
        private SoftDeleteFilter $softDeleteFilter,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $job = $this->lookup->job($uriVariables['uuid'] ?? null);
        if (!$job->isDeleted()) {
            throw new ConflictHttpException('Only soft-deleted download jobs can be permanently deleted.');
        }

        $this->softDeleteFilter->disabled(function () use ($job): void {
            $this->entityManager->wrapInTransaction(function () use ($job): void {
                foreach ($job->getFiles()->toArray() as $file) {
                    $file->removeDownloadJob($job);
                    $job->removeFile($file);
                    $usedByLiveJob = $file->getDownloadJob()->exists(static fn (int $key, DownloadJob $other): bool => !$other->isDeleted());
                    if (!$usedByLiveJob && !$file->isDeleted()) {
                        $file->setDeletedAt(new \DateTime());
                    }
                }
                $this->entityManager->flush();

                $this->entityManager->createQuery('DELETE FROM '.DownloadJobEvent::class.' e WHERE e.downloadJob = :job')
                    ->setParameter('job', $job->getId())
                    ->execute();
                $this->entityManager->createQuery('DELETE FROM '.DownloadJob::class.' j WHERE j.id = :job')
                    ->setParameter('job', $job->getId())
                    ->execute();
            });
        });
        $this->entityManager->detach($job);

        $this->logger->info('Download job permanently deleted by an admin', [
            'job' => $job->getUuid()?->toRfc4122(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ]);

        return null;
    }
}
