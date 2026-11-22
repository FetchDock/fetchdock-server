<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Enum\IntegrityStatus;
use App\State\Admin\AdminInstanceIntegrityProvider;

/**
 * The integrity check of the instance's code (ADR 0013): a signal that catches honest drift, not proof.
 */
#[ApiResource(
    shortName: 'AdminInstanceIntegrity',
    operations: [
        new Get(
            uriTemplate: '/admin/instance_integrity',
            description: 'The effective status (the worst check of the last 48 hours) and the latest check of each api and worker container in that period, with the paths that differ from the manifest.',
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminInstanceIntegrityProvider::class,
)]
final class AdminInstanceIntegrity
{
    public IntegrityStatus $status = IntegrityStatus::UNKNOWN;

    /** The FetchDock version this container runs */
    public string $appVersion = '';

    /** sha256 of this container's integrity manifest, null without one (development builds) */
    public ?string $manifestDigest = null;

    /** The commit the image was built from */
    public ?string $gitSha = null;

    /** Whether the check compares the manifest with the digest published on GitHub (INTEGRITY_CHECK_ONLINE) */
    public bool $checkOnline = true;

    /** @var list<AdminInstanceIntegrityCheck> newest first */
    public array $checks = [];
}
