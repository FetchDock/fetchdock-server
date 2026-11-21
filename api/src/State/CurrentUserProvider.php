<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Resource\CurrentUser;
use App\Security\CurrentOwner;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * GET /users/me.
 *
 * @implements ProviderInterface<CurrentUser>
 */
final readonly class CurrentUserProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private RoleHierarchyInterface $roleHierarchy,
        private CurrentOwner $currentOwner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CurrentUser
    {
        // The operation requires ROLE_USER, so there is a user
        $user = $this->security->getUser();

        $roles = array_values(array_unique($this->roleHierarchy->getReachableRoleNames($user->getRoles())));
        sort($roles);

        $currentUser = new CurrentUser();
        $currentUser->subject = $user->getUserIdentifier();
        $currentUser->uuid = $this->currentOwner->find()?->getUuid()?->toRfc4122();
        $currentUser->roles = $roles;

        return $currentUser;
    }
}
