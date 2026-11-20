<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Dto\DownloadedFileOutput;
use App\Entity\DownloadedFile;
use App\Repository\DownloadJobRepository;
use App\Security\CurrentOwner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /download_jobs/{downloadJobUuid}/files: the visible files of a job, for the job's owner only.
 *
 * The owner check can't live in the query (files aren't owned: one file can belong to jobs of
 * several owners), so the job is looked up by UUID and owner first, like GET /download_jobs/{uuid}.
 *
 * @implements ProviderInterface<DownloadedFileOutput>
 */
final readonly class DownloadedFileCollectionProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        private DownloadJobRepository $downloadJobRepository,
        private CurrentOwner $currentOwner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $owner = $this->currentOwner->find();
        $job = null !== $owner
            ? $this->downloadJobRepository->findOneBy(['uuid' => $uriVariables['downloadJobUuid'] ?? null, 'owner' => $owner])
            : null;
        if (null === $job) {
            throw new NotFoundHttpException('Not Found');
        }

        $files = $this->collectionProvider->provide($operation, $uriVariables, $context);
        $outputs = [];
        foreach ($files as $file) {
            /* @var DownloadedFile $file */
            $outputs[] = DownloadedFileOutput::forJob($file, $job);
        }

        if ($files instanceof PaginatorInterface) {
            return new TraversablePaginator(new \ArrayIterator($outputs), $files->getCurrentPage(), $files->getItemsPerPage(), $files->getTotalItems());
        }

        return $outputs;
    }
}
