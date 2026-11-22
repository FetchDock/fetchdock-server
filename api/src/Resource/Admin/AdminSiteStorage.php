<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\State\Admin\AdminSiteStorageProcessor;
use App\State\Admin\AdminSiteStorageProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Where a site's downloads are stored (ADR 0011).
 */
#[ApiResource(
    shortName: 'AdminSiteStorage',
    operations: [
        new Get(uriTemplate: '/admin/sites/{slug}/storage'),
        new Put(
            uriTemplate: '/admin/sites/{slug}/storage',
            description: "Sets the site's download directory (null: the default one). Applies to new downloads; jobs already started keep their directory. Existing files stay where they are, unless moveExisting starts a file move batch (409 while one of the site is active).",
            extraProperties: ['standard_put' => true],
        ),
    ],
    normalizationContext: ['groups' => ['site_storage:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['site_storage:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminSiteStorageProvider::class,
    processor: AdminSiteStorageProcessor::class,
)]
final class AdminSiteStorage
{
    /** The site's slug */
    #[ApiProperty(identifier: true)]
    #[Groups(['site_storage:read'])]
    public string $slug = '';

    /** The site's own directory, null when it uses the default one */
    #[Groups(['site_storage:read', 'site_storage:write'])]
    public ?AdminDownloadDirectory $directory = null;

    /** The directory new downloads of the site go to (read-only) */
    #[Groups(['site_storage:read'])]
    public ?AdminDownloadDirectory $effectiveDirectory = null;

    /** Files of the site's jobs (read-only) */
    #[Groups(['site_storage:read'])]
    public int $fileCount = 0;

    /** Of those, the files stored in another directory than the effective one (read-only) */
    #[Groups(['site_storage:read'])]
    public int $filesElsewhere = 0;

    /** Also move the site's existing files to the directory, as a tracked batch (see activeMove). 409 while a batch of the site is active */
    #[Groups(['site_storage:write'])]
    public bool $moveExisting = false;

    /** The site's queued or running file move batch (read-only) */
    #[Groups(['site_storage:read'])]
    public ?AdminFileMoveBatch $activeMove = null;
}
