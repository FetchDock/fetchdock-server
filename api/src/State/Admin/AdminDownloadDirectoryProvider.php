<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\DownloadDirectory;
use App\Repository\DownloadDirectoryRepository;
use App\Resource\Admin\AdminDownloadDirectory;

/**
 * @implements ProviderInterface<AdminDownloadDirectory>
 */
final readonly class AdminDownloadDirectoryProvider implements ProviderInterface
{
    public function __construct(private DownloadDirectoryRepository $directoryRepository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $files = $this->directoryRepository->countFiles();
            $sites = $this->directoryRepository->countSites();

            return array_map(
                static fn (DownloadDirectory $directory): AdminDownloadDirectory => AdminDownloadDirectory::fromEntity($directory, $files[$directory->getId()] ?? 0, $sites[$directory->getId()] ?? 0),
                $this->directoryRepository->findBy([], ['isDefault' => 'DESC', 'name' => 'ASC']),
            );
        }

        $directory = $this->find($uriVariables['id'] ?? null);

        return null === $directory ? null : $this->toResource($directory);
    }

    public function find(mixed $id): ?DownloadDirectory
    {
        return is_numeric($id) ? $this->directoryRepository->find((int) $id) : null;
    }

    public function toResource(DownloadDirectory $directory): AdminDownloadDirectory
    {
        return AdminDownloadDirectory::fromEntity(
            $directory,
            $this->directoryRepository->countFiles()[$directory->getId()] ?? 0,
            $this->directoryRepository->countSites()[$directory->getId()] ?? 0,
        );
    }
}
