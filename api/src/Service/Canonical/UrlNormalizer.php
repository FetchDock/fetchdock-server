<?php

namespace App\Service\Canonical;

/**
 * Normalizes a URL according to a canonicalization profile: lowercase scheme and host, no default
 * port, user info or fragment, the profile's query parameters only (sorted), and the profile's
 * host and path rules.
 */
final class UrlNormalizer
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function normalize(string $url, CanonicalProfile $profile): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = rtrim(strtolower($parts['host']), '.');
        if ($profile->stripWww && str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $port = $parts['port'] ?? null;
        if (null !== $port && (self::DEFAULT_PORTS[$scheme] ?? null) === $port) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        foreach ($profile->pathRewrites as $rewrite) {
            $path = preg_replace($rewrite['pattern'], $rewrite['replacement'], $path) ?? $path;
        }
        if ($profile->stripTrailingSlash) {
            $path = rtrim($path, '/');
        }
        if ('' === $path) {
            $path = '/';
        }

        $query = $this->normalizeQuery($parts['query'] ?? '', $profile);

        return $scheme.'://'.$host.(null !== $port ? ':'.$port : '').$path.('' !== $query ? '?'.$query : '');
    }

    private function normalizeQuery(string $query, CanonicalProfile $profile): string
    {
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if ('' === $name) {
                continue;
            }
            if (null !== $profile->keepParams && !self::matchesAny($name, $profile->keepParams)) {
                continue;
            }
            if (self::matchesAny($name, $profile->dropParams)) {
                continue;
            }
            $pairs[] = $pair;
        }

        // Stable order: by name, then value, so "b=1&a=2" and "a=2&b=1" give the same key
        usort($pairs, static fn (string $a, string $b): int => [explode('=', $a, 2)[0], $a] <=> [explode('=', $b, 2)[0], $b]);

        return implode('&', $pairs);
    }

    /**
     * @param list<string> $globs
     */
    private static function matchesAny(string $name, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $name, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
