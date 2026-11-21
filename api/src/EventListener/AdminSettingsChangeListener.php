<?php

namespace App\EventListener;

use App\Entity\DownloaderOptionPolicy;
use App\Entity\SiteSetting;
use App\Mercure\SettingsChangePublisher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Tells every user's clients about admin changes that affect their effective settings (#101): site defaults
 * (SiteSetting, changed by app:sites:sync) and downloader option policies. Events are collected on flush and published
 * after it, one per changed site or downloader, on every user's settings topic (see SettingsChangePublisher).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AdminSettingsChangeListener
{
    /** @var array<string, array{layer: SettingsChangePublisher::LAYER_*, keys: array<string, string>, action: SettingsChangePublisher::ACTION_*}> */
    private array $events = [];

    public function __construct(private readonly SettingsChangePublisher $publisher)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()] as $entities) {
            foreach ($entities as $entity) {
                $this->collect($unitOfWork, $entity, SettingsChangePublisher::ACTION_UPDATED);
            }
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->collect($unitOfWork, $entity, SettingsChangePublisher::ACTION_DELETED);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $events = $this->events;
        $this->events = [];
        foreach ($events as $event) {
            $this->publisher->publishToAllUsers($event['layer'], $event['keys'], $event['action']);
        }
    }

    /**
     * @param SettingsChangePublisher::ACTION_* $action
     */
    private function collect(UnitOfWork $unitOfWork, object $entity, string $action): void
    {
        if ($entity instanceof SiteSetting) {
            $this->add(SettingsChangePublisher::LAYER_SITE_DEFAULTS, 'slug', $entity->getSite()?->getSlug(), $action);
        } elseif ($entity instanceof DownloaderOptionPolicy) {
            // Removing an override doesn't remove the downloader's policy: it's always an update of the policy
            $this->add(SettingsChangePublisher::LAYER_OPTION_POLICY, 'downloader', $entity->getDownloader(), SettingsChangePublisher::ACTION_UPDATED);
            // A policy moved to another downloader also changed the previous one
            $previous = $unitOfWork->getEntityChangeSet($entity)['downloader'][0] ?? null;
            if (\is_string($previous)) {
                $this->add(SettingsChangePublisher::LAYER_OPTION_POLICY, 'downloader', $previous, SettingsChangePublisher::ACTION_UPDATED);
            }
        }
    }

    /**
     * @param SettingsChangePublisher::LAYER_*  $layer
     * @param SettingsChangePublisher::ACTION_* $action
     */
    private function add(string $layer, string $key, ?string $value, string $action): void
    {
        if (null === $value) {
            return;
        }
        // One event per changed site or downloader
        $this->events[$layer."\0".$value] = ['layer' => $layer, 'keys' => [$key => $value], 'action' => $action];
    }
}
