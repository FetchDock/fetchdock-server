<?php

namespace App\Resource\Admin;

use App\Entity\InstanceIntegrityCheck;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;

/**
 * The latest integrity check of one container (AdminInstanceIntegrity).
 */
final class AdminInstanceIntegrityCheck
{
    /** The container's host name */
    public string $host = '';

    public IntegrityRole $role = IntegrityRole::API;

    public IntegrityStatus $status = IntegrityStatus::UNKNOWN;

    public ?string $manifestDigest = null;

    public string $appVersion = '';

    /**
     * Paths that differ from the manifest (at most 50).
     *
     * @var list<string>
     */
    public array $mismatches = [];

    public ?\DateTimeImmutable $checkedAt = null;

    public static function fromEntity(InstanceIntegrityCheck $check): self
    {
        $resource = new self();
        $resource->host = $check->getHost();
        $resource->role = $check->getRole();
        $resource->status = $check->getStatus();
        $resource->manifestDigest = $check->getManifestDigest();
        $resource->appVersion = $check->getAppVersion();
        $resource->mismatches = $check->getMismatches();
        $resource->checkedAt = $check->getCheckedAt();

        return $resource;
    }
}
