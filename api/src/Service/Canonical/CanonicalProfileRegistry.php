<?php

namespace App\Service\Canonical;

use App\Entity\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The canonicalization profiles: code profiles (config/packages/url_canonicalization.yaml), each
 * extending "default", adjusted per site by Site::getCanonicalization() (config/sites.yaml).
 *
 * A site uses the profile it names ("profile:"), else the code profile named after its slug, else "default".
 */
final class CanonicalProfileRegistry
{
    public const string DEFAULT_PROFILE = 'default';

    /** @var array<string, CanonicalProfile> */
    private array $profiles = [];

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(
        #[Autowire('%app.url_canonicalization.profiles%')]
        array $profiles,
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

        $overrides = $site->getCanonicalization() ?? [];
        $base = $this->resolveBase($site->getSlug(), $overrides);
        unset($overrides['profile']);
        if ([] === array_filter($overrides, static fn (mixed $value): bool => null !== $value)) {
            return $base;
        }

        try {
            return $base->with($overrides, $base->name);
        } catch (\InvalidArgumentException) {
            // Validated when synchronised; a broken stored value must not break submissions
            return $base;
        }
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
