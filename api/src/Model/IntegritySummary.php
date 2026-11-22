<?php

namespace App\Model;

use App\Enum\IntegrityStatus;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The instance's integrity status for clients and the policy (ADR 0013). A signal that catches honest drift, not
 * proof. Serialized in full by PrivacyPolicy (no groups) and AdminPrivacyPolicy (privacy_policy:read).
 */
final class IntegritySummary
{
    /** The worst check of the last 48 hours; unknown without one */
    #[Groups(['privacy_policy:read'])]
    public IntegrityStatus $status = IntegrityStatus::UNKNOWN;

    /** sha256 of the image's integrity manifest; compare it with the release asset integrity-manifest.sha256 */
    #[Groups(['privacy_policy:read'])]
    public ?string $manifestDigest = null;

    /** The commit the image was built from, from the manifest */
    #[Groups(['privacy_policy:read'])]
    public ?string $gitSha = null;

    /** The latest check */
    #[Groups(['privacy_policy:read'])]
    public ?\DateTimeImmutable $checkedAt = null;
}
