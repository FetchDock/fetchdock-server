<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\DownloadDirectory;
use App\Repository\DownloadDirectoryRepository;
use App\Resource\Admin\AdminDownloadDirectory;
use App\Service\Storage\DownloadDirectoryPathValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates, updates and deletes download directories. A directory's path only changes while it holds no files,
 * or with relocate (the admin moved the tree): file locations are relative, so relocating updates one row.
 *
 * @implements ProcessorInterface<AdminDownloadDirectory, AdminDownloadDirectory|null>
 */
final readonly class AdminDownloadDirectoryProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminDownloadDirectoryProvider $provider,
        private DownloadDirectoryRepository $directoryRepository,
        private DownloadDirectoryPathValidator $pathValidator,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AdminDownloadDirectory
    {
        if ($operation instanceof Post) {
            return $this->create($data);
        }

        $directory = $this->provider->find($uriVariables['id'] ?? null) ?? throw new NotFoundHttpException('Download directory not found.');

        if ($operation instanceof DeleteOperationInterface) {
            $this->delete($directory);

            return null;
        }

        return $this->update($directory, $data);
    }

    private function create(AdminDownloadDirectory $data): AdminDownloadDirectory
    {
        $violations = new ConstraintViolationList();
        $this->validateName($violations, $data, null);
        $this->validatePath($violations, $data, null);
        $this->throwIfInvalid($violations);

        $directory = new DownloadDirectory($data->name, $this->pathValidator->canonicalize($data->path))
            ->setVerifyChecksum($data->verifyChecksum);
        $this->entityManager->persist($directory);
        $this->entityManager->flush();
        if ($data->isDefault) {
            $this->directoryRepository->makeDefault($directory);
        }
        $this->log('Download directory created', $directory);

        return $this->provider->toResource($directory);
    }

    private function update(DownloadDirectory $directory, AdminDownloadDirectory $data): AdminDownloadDirectory
    {
        $violations = new ConstraintViolationList();
        $this->validateName($violations, $data, $directory);

        $path = str_starts_with($data->path, '/') ? $this->pathValidator->canonicalize($data->path) : $data->path;
        $pathChanged = $path !== $directory->getPath();
        if ($pathChanged) {
            $fileCount = $this->directoryRepository->countFiles()[$directory->getId()] ?? 0;
            if ($fileCount > 0 && !$data->relocate) {
                $violations->add($this->violation(sprintf('The directory holds %d files. Move them first, or set relocate: true if the whole tree was already moved to the new path.', $fileCount), 'path', $data));
            } else {
                $this->validatePath($violations, $data, $directory);
            }
        }
        if ($directory->isDefault() && !$data->isDefault) {
            $violations->add($this->violation('Make another directory the default one instead.', 'isDefault', $data));
        }
        $this->throwIfInvalid($violations);

        $previousPath = $directory->getPath();
        $directory->setName($data->name)->setPath($path)->setVerifyChecksum($data->verifyChecksum);
        $this->entityManager->flush();
        if ($data->isDefault && !$directory->isDefault()) {
            $this->directoryRepository->makeDefault($directory);
        }
        $this->log($pathChanged ? 'Download directory relocated' : 'Download directory updated', $directory, $pathChanged ? ['previous_path' => $previousPath] : []);

        return $this->provider->toResource($directory);
    }

    private function delete(DownloadDirectory $directory): void
    {
        if ($directory->isDefault()) {
            throw new ConflictHttpException('The default directory cannot be deleted.');
        }
        $files = $this->directoryRepository->countFiles()[$directory->getId()] ?? 0;
        $sites = $this->directoryRepository->countSites()[$directory->getId()] ?? 0;
        if ($files > 0 || $sites > 0) {
            throw new ConflictHttpException(sprintf('The directory is in use by %d files and %d sites.', $files, $sites));
        }

        $this->log('Download directory deleted', $directory);
        $this->entityManager->remove($directory);
        $this->entityManager->flush();
    }

    private function validateName(ConstraintViolationList $violations, AdminDownloadDirectory $data, ?DownloadDirectory $self): void
    {
        $existing = $this->directoryRepository->findOneBy(['name' => $data->name]);
        if (null !== $existing && $existing !== $self) {
            $violations->add($this->violation('Another directory has this name.', 'name', $data));
        }
    }

    private function validatePath(ConstraintViolationList $violations, AdminDownloadDirectory $data, ?DownloadDirectory $self): void
    {
        if (null !== $problem = $this->pathValidator->validate($data->path, $self)) {
            $violations->add($this->violation($problem, 'path', $data));
        }
    }

    private function violation(string $message, string $property, AdminDownloadDirectory $data): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], $data, $property, $data->{$property});
    }

    private function throwIfInvalid(ConstraintViolationList $violations): void
    {
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, DownloadDirectory $directory, array $context = []): void
    {
        $this->logger->info($message, $context + [
            'directory' => $directory->getId(),
            'path' => $directory->getPath(),
            'admin' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }
}
