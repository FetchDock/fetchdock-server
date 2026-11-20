<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\DownloadJobEvent;
use App\State\Admin\AdminDownloadJobEventProvider;

/**
 * An event of any user's download job (including soft-deleted jobs), for admins.
 * The same data the owner sees on /download_jobs/{uuid}/events.
 */
#[ApiResource(
    shortName: 'AdminDownloadJobEvent',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/download_jobs/{uuid}/events',
            uriVariables: ['uuid' => new Link(fromClass: AdminDownloadJob::class, identifiers: ['uuid'])],
            description: "A job's events, oldest first.",
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_VIEW_ALL_DOWNLOADS')",
    provider: AdminDownloadJobEventProvider::class,
)]
final class AdminDownloadJobEvent
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public ?string $event = null;

    public ?string $source = null;

    public ?string $workerIdentifier = null;

    public ?string $updateMessage = null;

    public ?string $exceptionMessage = null;

    public ?array $context = null;

    public ?array $metadata = null;

    public ?\DateTimeInterface $createdAt = null;

    public static function fromEntity(DownloadJobEvent $event): self
    {
        $output = new self();
        $output->id = (int) $event->getId();
        $output->event = $event->getEvent();
        $output->source = $event->getSource();
        $output->workerIdentifier = $event->getWorkerIdentifier();
        $output->updateMessage = $event->getUpdateMessage();
        $output->exceptionMessage = $event->getExceptionMessage();
        $output->context = $event->getContext();
        $output->metadata = $event->getMetadata();
        $output->createdAt = $event->getCreatedAt();

        return $output;
    }
}
