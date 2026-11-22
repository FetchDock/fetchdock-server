<?php

namespace App\EventListener;

use App\Repository\PrivacyPolicySettingsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Records when a user who may view all downloads without being an administrator signs in, so the privacy policy
 * can name them (ADR 0013). Roles come from the identity provider with every request, so the server can't list
 * who holds them. Written at most once a day.
 */
#[AsEventListener]
final readonly class ViewAllRoleListener
{
    public const string CACHE_KEY = 'privacy_policy.view_all_role_seen';

    public function __construct(
        private RoleHierarchyInterface $roleHierarchy,
        private PrivacyPolicySettingsRepository $settingsRepository,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $roles = $this->roleHierarchy->getReachableRoleNames($event->getAuthenticatedToken()->getRoleNames());
        if (!\in_array('ROLE_VIEW_ALL_DOWNLOADS', $roles, true) || \in_array('ROLE_ADMIN', $roles, true)) {
            return;
        }

        $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): bool {
            $item->expiresAfter(86400);
            $this->settingsRepository->markViewAllRoleSeen($this->clock->now());

            return true;
        });
    }
}
