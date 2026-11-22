<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\DirectoryFileMoveRequest;
use App\Repository\FileMoveBatchRepository;
use App\Repository\SiteRepository;
use App\Resource\Admin\AdminFileMoveBatch;
use App\Service\FileMove\FileMovePlanner;
use App\Service\FileMove\FileMoveProgressPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts (site or directory scope) and cancels file move batches.
 *
 * @implements ProcessorInterface<DirectoryFileMoveRequest|null, AdminFileMoveBatch>
 */
final readonly class AdminFileMoveBatchProcessor implements ProcessorInterface
{
    public function __construct(
        private FileMovePlanner $planner,
        private FileMoveBatchRepository $batchRepository,
        private SiteRepository $siteRepository,
        private AdminDownloadDirectoryProvider $directoryProvider,
        private FileMoveProgressPublisher $publisher,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminFileMoveBatch
    {
        $requestedBy = $this->security->getUser()?->getUserIdentifier();

        return match ($operation->getName()) {
            'move_site_files' => AdminFileMoveBatch::fromEntity($this->planner->planSite(
                $this->siteRepository->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw new NotFoundHttpException('Site not found.'),
                $requestedBy,
            )),
            'move_directory_files' => AdminFileMoveBatch::fromEntity($this->planner->planDirectory(
                $this->directoryProvider->find($uriVariables['id'] ?? null) ?? throw new NotFoundHttpException('Download directory not found.'),
                $this->directoryProvider->find($data->targetDirectory?->id) ?? throw new NotFoundHttpException('Target directory not found.'),
                $data->reassignSites,
                $requestedBy,
            )),
            'cancel_file_move' => $this->cancel($uriVariables['id'] ?? null),
            default => throw new \LogicException('Unknown operation '.$operation->getName()),
        };
    }

    private function cancel(mixed $id): AdminFileMoveBatch
    {
        $batch = is_numeric($id) ? $this->batchRepository->find((int) $id) : null;
        if (null === $batch) {
            throw new NotFoundHttpException('File move batch not found.');
        }
        if (!$this->batchRepository->cancel((int) $batch->getId())) {
            throw new ConflictHttpException('The batch is not active.');
        }
        $this->publisher->publish((int) $batch->getId(), force: true);
        $this->entityManager->refresh($batch);

        return AdminFileMoveBatch::fromEntity($batch);
    }
}
