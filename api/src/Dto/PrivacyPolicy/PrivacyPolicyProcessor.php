<?php

namespace App\Dto\PrivacyPolicy;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Another service provider that processes data for the operator, such as a backup service (AdminPrivacyPolicy).
 */
final class PrivacyPolicyProcessor
{
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 500)]
    public ?string $purpose = null;

    /** ISO 3166-1 alpha-2 code in upper case */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Country]
    public ?string $country = null;
}
