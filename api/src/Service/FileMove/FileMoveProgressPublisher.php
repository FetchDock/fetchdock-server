<?php

namespace App\Service\FileMove;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Entity\FileMoveBatch;
use App\Mercure\AdminTopics;
use App\Resource\Admin\AdminFileMoveBatch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Publishes a batch's AdminFileMoveBatch output privately on its admin topic: on status changes, and otherwise at most
 * once per second across workers (FileMoveBatchRepository::claimProgressPublish()). A failing hub never fails a move.
 */
final readonly class FileMoveProgressPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $hub,
        private AdminTopics $adminTopics,
        private SerializerInterface $serializer,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(int $batchId, bool $force = false): void
    {
        try {
            if (!$force && !$this->entityManager->getRepository(FileMoveBatch::class)->claimProgressPublish($batchId)) {
                return;
            }
            $batch = $this->entityManager->find(FileMoveBatch::class, $batchId);
            if (null === $batch) {
                return;
            }
            // Counters are updated in SQL by every worker
            $this->entityManager->refresh($batch);

            $operation = $this->resourceMetadataCollectionFactory->create(AdminFileMoveBatch::class)->getOperation();
            $context = ['operation' => $operation, 'resource_class' => AdminFileMoveBatch::class] + ($operation->getNormalizationContext() ?? []);
            $data = $this->serializer->serialize(AdminFileMoveBatch::fromEntity($batch), 'jsonld', $context);
            $this->hub->publish(new Update([$this->adminTopics->fileMove($batchId), $this->adminTopics->fileMoves()], $data, private: true));
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to publish the progress of a file move batch', ['batch' => $batchId, 'exception' => $e]);
        }
    }
}
