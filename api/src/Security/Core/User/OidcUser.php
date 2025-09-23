<?php

namespace App\Security\Core\User;

use Symfony\Component\Security\Core\User\UserInterface;

class OidcUser implements UserInterface
{
    public function __construct(
        private(set) string $issuer,
        private(set) string $sub,
        private(set) string $name = '',
        private(set) string $givenName = '',
        private(set) string $nickName = '',
        private(set) ?string $email = null,
        private(set) ?string $preferredUsername = null,
        private(set) array $roles = [],
    )
    {
    }


    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {

    }

    public function getUserIdentifier(): string
    {
        return $this->nickName ?? $this->email ?? $this->sub;
    }
}
