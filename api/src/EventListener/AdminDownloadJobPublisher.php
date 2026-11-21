<?php

namespace App\EventListener;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadJob;
use App\Mercure\AdminTopics;
use App\Resource\Admin\AdminDownloadJob;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Publishes live updates of download jobs for the admin views (#55): after each flush, every inserted, updated or
 * (soft-)deleted job is published as its AdminDownloadJob output, privately, on its admin topic (see AdminTopics).
 * New jobs are also published on the collection topic, so open lists can show them (#86).
 *
 * The payload is the resource's JSON-LD item output, so the API Platform admin can replace its cached record with it.
 * A failing hub is logged and never breaks the flush.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: SoftDeleteableListener::POST_SOFT_DELETE)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AdminDownloadJobPublisher
{
    /** @var array<int, DownloadJob> changed jobs by object id, published after the flush */
    private array $jobs = [];

    /** @var array<int, true> object ids of the inserted jobs among */
    private array $inserted = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly AdminTopics $adminTopics,
        private readonly SerializerInterface $serializer,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        private readonly SoftDeleteFilter $softDeleteFilter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof DownloadJob) {
                $this->inserted[spl_object_id($entity)] = true;
            }
        }
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof DownloadJob) {
                    $this->jobs[spl_object_id($entity)] = $entity;
                }
            }
        }
    }

    /**
     * Gedmo turns removals into "extra updates" in its own onFlush, so soft-deleted jobs are in neither the scheduled
     * updates nor the deletions when this listener's onFlush runs after it.
     */
    public function postSoftDelete(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof DownloadJob) {
            $this->jobs[spl_object_id($entity)] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $jobs = $this->jobs;
        $inserted = $this->inserted;
        $this->jobs = [];
        $this->inserted = [];
        if ([] === $jobs) {
            return;
        }

        $operation = $this->resourceMetadataCollectionFactory->create(AdminDownloadJob::class)->getOperation();
        $context = ['operation' => $operation, 'resource_class' => AdminDownloadJob::class] + ($operation->getNormalizationContext() ?? []);

        foreach ($jobs as $objectId => $job) {
            $uuid = $job->getUuid()?->toRfc4122();
            if (null === $uuid || null === $job->getId()) {
                continue;
            }

            try {
                // Like the admin endpoints: the job's relations include soft-deleted records
                $data = $this->softDeleteFilter->disabled(fn (): string => $this->serializer->serialize(AdminDownloadJob::fromEntity($job), 'jsonld', $context));
                $topics = [$this->adminTopics->downloadJob($uuid)];
                if (isset($inserted[$objectId])) {
                    $topics[] = $this->adminTopics->downloadJobs();
                }
                $this->hub->publish(new Update($topics, $data, private: true));
            } catch (\Throwable $e) {
                $this->logger->warning('Unable to publish the admin update of a download job', ['job' => $uuid, 'exception' => $e]);
            }
        }
    }
}
