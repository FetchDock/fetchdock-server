<?php

namespace App\Security\Core\User;

use Symfony\Component\Security\Core\User\UserInterface;

class OidcUser implements UserInterface
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $sub,
        private readonly string $name = '',
        private readonly string $givenName = '',
        private readonly string $nickName = '',
        private readonly ?string $email = null,
        private readonly ?string $preferredUsername = null,
        private readonly array $roles = [],
    ) {
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        // As per OpenID spec always return sub, as this is a local unique AND NEVER REASSIGNED identifier
        // within the Issuer
        // @see https://openid.net/specs/openid-connect-core-1_0.html#IDToken

        return $this->sub;
    }
}
