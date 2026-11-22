<?php

namespace App\Dto\PrivacyPolicy;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The OpenID Connect provider users sign in with (AdminPrivacyPolicy).
 */
final class PrivacyPolicyIdentityProvider
{
    /** For example "Authentik, run by the operator" (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 2048)]
    public ?string $privacyPolicyUrl = null;
}
