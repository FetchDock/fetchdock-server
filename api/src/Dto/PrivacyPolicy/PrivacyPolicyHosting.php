<?php

namespace App\Dto\PrivacyPolicy;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where the instance runs (AdminPrivacyPolicy).
 */
final class PrivacyPolicyHosting
{
    /** The hosting provider, or "self-hosted" (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $provider = null;

    /** ISO 3166-1 alpha-2 code in upper case, e.g. "NL" (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Country]
    public ?string $country = null;
}
