<?php

namespace App\Dto\PrivacyPolicy;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The controller named in the privacy policy (AdminPrivacyPolicy).
 */
final class PrivacyPolicyOperator
{
    /** Person or organization running the instance (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    /** Postal address; line breaks are kept */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 1000)]
    public ?string $address = null;

    /** Where users send privacy requests (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    public ?string $contactEmail = null;

    /** Data protection officer, if there is one */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $dpoContact = null;
}
