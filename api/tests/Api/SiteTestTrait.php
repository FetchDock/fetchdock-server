<?php

namespace App\Tests\Api;

use App\Service\Site\SiteResolver;
use App\Service\Site\SiteSynchronizer;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Curated sites from config/sites.yaml, synchronised with the fake extractor matcher.
 */
trait SiteTestTrait
{
    private function syncSites(): void
    {
        static::getContainer()->get(SiteSynchronizer::class)->sync();
        static::getContainer()->get(TagAwareCacheInterface::class)->invalidateTags([SiteResolver::CACHE_TAG]);
    }

    /**
     * Drops JSON-LD keywords (@id, @var, ...) of nested objects, keeping the top-level ones.
     */
    private static function withoutJsonLdKeywords(array $data, bool $nested = false): array
    {
        foreach ($data as $key => $value) {
            if ($nested && is_string($key) && str_starts_with($key, '@')) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::withoutJsonLdKeywords($value, true);
            }
        }

        return $data;
    }

    private static function auth(string $user = 'user'): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass')];
    }
}
