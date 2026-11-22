<?php

namespace App\Service\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The DOWNLOAD_ROOTS allowlist: download directories must resolve inside one of these paths.
 */
final readonly class DownloadRoots
{
    /** @var list<string> */
    private array $roots;

    /**
     * @param list<string> $roots
     */
    public function __construct(#[Autowire(param: 'app.download_roots')] array $roots)
    {
        $roots = array_filter(array_map('trim', $roots), static fn (string $root): bool => '' !== $root);
        $this->roots = array_values(array_map(Paths::normalize(...), $roots));
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->roots;
    }

    /**
     * Whether the path is a root or inside one, after resolving symlinks of both (so a link can't escape a root).
     */
    public function contains(string $path): bool
    {
        $resolved = Paths::canonicalize($path);
        foreach ($this->roots as $root) {
            if (Paths::isWithin($resolved, Paths::canonicalize($root))) {
                return true;
            }
        }

        return false;
    }
}
