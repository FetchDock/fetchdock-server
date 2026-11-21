<?php

namespace App\Service\Canonical;

use App\Entity\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The canonicalization profiles: code profiles (config/packages/url_canonicalization.yaml), each
 * extending "default", adjusted per site by Site::getCanonicalization() (config/sites.yaml), then by
 * the site's SiteCanonicalizationOverride (admin).
 *
 * A site uses the profile its admin override or settings name ("profile"), else the code profile named after
 * its slug, else "default". Site profiles are cached (tag CACHE_TAG).
 */
final class CanonicalProfileRegistry
{
    public const string DEFAULT_PROFILE = 'default';
    /** Tag of the cached site profiles, invalidated when sites' settings or overrides change */
    public const string CACHE_TAG = 'canonical_profiles';
    private const int CACHE_TTL = 86400;

    /** @var array<string, CanonicalProfile> */
    private array $profiles = [];

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(
        #[Autowire('%app.url_canonicalization.profiles%')]
        array $profiles,
        private readonly ?TagAwareCacheInterface $cache = null,
    ) {
        $base = new CanonicalProfile(self::DEFAULT_PROFILE);
        $this->profiles[self::DEFAULT_PROFILE] = $base->with($profiles[self::DEFAULT_PROFILE] ?? [], self::DEFAULT_PROFILE, CanonicalProfile::CODE_FIELDS);

        foreach ($profiles as $name => $fields) {
            if (self::DEFAULT_PROFILE === $name) {
                continue;
            }
            if (!is_string($name) || !is_array($fields)) {
                throw new \InvalidArgumentException('Canonicalization profiles must map names to field maps.');
            }
            $this->profiles[$name] = $this->profiles[self::DEFAULT_PROFILE]->with($fields, $name, CanonicalProfile::CODE_FIELDS);
        }
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function get(string $name): CanonicalProfile
    {
        return $this->profiles[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown canonicalization profile "%s".', $name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    public function forSite(?Site $site): CanonicalProfile
    {
        if (null === $site) {
            return $this->profiles[self::DEFAULT_PROFILE];
        }
        if (null === $this->cache || null === $site->getSlug()) {
            return $this->resolveForSite($site);
        }

        return $this->cache->get('canonical_profile.'.$site->getSlug(), function (ItemInterface $item) use ($site): CanonicalProfile {
            $item->expiresAfter(self::CACHE_TTL);
            $item->tag(self::CACHE_TAG);

            return $this->resolveForSite($site);
        });
    }

    /**
     * Drops the cached site profiles: call after changing sites' canonicalization settings or overrides.
     */
    public function invalidate(): void
    {
        $this->cache?->invalidateTags([self::CACHE_TAG]);
    }

    /**
     * The site's profile: the base profile adjusted by its config/sites.yaml settings, then by its admin override.
     */
    private function resolveForSite(Site $site): CanonicalProfile
    {
        $layers = [$site->getCanonicalization() ?? [], $site->getCanonicalizationOverride()?->toSettings() ?? []];
        $profileName = $layers[1]['profile'] ?? $layers[0]['profile'] ?? null;
        $profile = $this->resolveBase($site->getSlug(), ['profile' => $profileName]);

        foreach ($layers as $settings) {
            unset($settings['profile']);
            $settings = array_filter($settings, static fn (mixed $value): bool => null !== $value);
            if ([] === $settings) {
                continue;
            }
            try {
                $profile = $profile->with($settings, $profile->name);
            } catch (\InvalidArgumentException) {
                // Validated when written; a broken stored value must not break submissions
            }
        }

        return $profile;
    }

    /**
     * Validates a site's canonicalization settings (sites.yaml, admin overrides).
     *
     * @param array<string, mixed> $settings
     *
     * @throws \InvalidArgumentException
     */
    public function validate(array $settings): void
    {
        $base = $this->resolveBase(null, $settings, strict: true);
        unset($settings['profile']);
        $base->with($settings, $base->name);
    }

    private function resolveBase(?string $slug, array $settings, bool $strict = false): CanonicalProfile
    {
        $name = $settings['profile'] ?? null;
        if (null !== $name) {
            if (is_string($name) && isset($this->profiles[$name])) {
                return $this->profiles[$name];
            }
            if ($strict) {
                throw new \InvalidArgumentException(sprintf('Unknown canonicalization profile "%s"; known: %s.', is_scalar($name) ? $name : get_debug_type($name), implode(', ', $this->names())));
            }
        }

        return $this->profiles[$slug ?? ''] ?? $this->profiles[self::DEFAULT_PROFILE];
    }
}
