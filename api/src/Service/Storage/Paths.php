<?php

namespace App\Service\Storage;

/**
 * Lexical and filesystem path helpers for absolute POSIX paths.
 */
final class Paths
{
    /**
     * Removes empty, "." and ".." segments and the trailing slash, without touching the filesystem.
     */
    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * The real path when it (or its parent directory) exists, otherwise the normalized path.
     */
    public static function canonicalize(string $path): string
    {
        $normalized = self::normalize($path);
        $real = realpath($normalized);
        if (false !== $real) {
            return $real;
        }

        $parent = realpath(\dirname($normalized));

        return false !== $parent ? rtrim($parent, '/').'/'.basename($normalized) : $normalized;
    }

    public static function isWithin(string $path, string $directory): bool
    {
        return $path === $directory || '/' === $directory || str_starts_with($path, $directory.'/');
    }

    /**
     * The path relative to the directory, or null if it isn't strictly inside it.
     */
    public static function relativeTo(string $path, string $directory): ?string
    {
        if ($path === $directory || !self::isWithin($path, $directory)) {
            return null;
        }

        return substr($path, '/' === $directory ? 1 : \strlen($directory) + 1);
    }
}
