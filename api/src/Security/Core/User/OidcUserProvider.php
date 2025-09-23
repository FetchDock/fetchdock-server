<?php
namespace App\Security\Core\User;

use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class OidcUserProvider implements AttributesBasedUserProviderInterface
{
    public function loadUserByIdentifier(string $identifier, array $attributes = []): UserInterface
    {
        // Here you would typically fetch the user from your database using the identifier
        // and attributes provided. For demonstration purposes, we'll create a new OidcUser.

        // Example: Assuming $attributes contains 'issuer', 'sub', 'email', 'preferredUsername', and 'roles'
        $issuer = $attributes['issuer'] ?? 'default_issuer';
        $sub = $attributes['sub'] ?? $identifier;
        $email = $attributes['email'] ?? null;
        $preferredUsername = $attributes['preferred_username'] ?? null;
        $roles = $attributes['roles'] ?? ['ROLE_USER'];

        // For authentik, we must use the groups claim and convert group names from "Group Name" to "ROLE_GROUP_NAME"
        if (isset($attributes['groups']) && is_array($attributes['groups'])) {
            $roles = array_map(fn($group) => 'ROLE_' . strtoupper(str_replace(' ', '_', $group)), $attributes['groups']);
        }

        return new OidcUser(
            issuer: $issuer,
            sub: $sub,
            name: $attributes['name'] ?? '',
            givenName: $attributes['given_name'] ?? '',
            nickName: $attributes['nickname'] ?? '',
            email: $email,
            preferredUsername: $preferredUsername,
            roles: $roles
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // Since OidcUser is stateless, we can simply return the user as is.
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return OidcUser::class === $class || is_subclass_of($class, OidcUser::class);
    }
}
