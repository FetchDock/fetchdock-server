<?php

namespace App\Service\FileMove;

/**
 * Moves files between download directories without ever overwriting or losing one (ADR 0011):
 * - same filesystem: a hard link at the target (atomic, and unlike rename() it fails if the target exists);
 * - otherwise: a copy to "<target>.fetchdock-part", fsync, verification (size, plus SHA-256 if asked), then a link
 *   to the final name.
 * Either way the source stays until finish(), which the caller runs only once the new location is committed;
 * undo() removes the target of a transfer whose commit failed.
 */
class FileMover
{
    public const string LINKED = 'linked';
    public const string COPIED = 'copied';
    public const string PART_SUFFIX = '.fetchdock-part';

    private const int CHUNK = 8 * 1024 * 1024;

    /**
     * @param bool $alwaysCopy copy even on the same filesystem (tests use it to cover cross-device moves)
     */
    public function __construct(private readonly bool $alwaysCopy = false)
    {
    }

    /**
     * @return self::LINKED|self::COPIED
     *
     * @throws FileMoveFailure   when the source is missing, the target exists or the copy doesn't verify
     * @throws \RuntimeException on I/O errors, which are worth retrying
     */
    public function transfer(string $source, string $target, bool $verifyChecksum): string
    {
        clearstatcache();
        if (!is_file($source)) {
            throw new FileMoveFailure('missing source');
        }
        if (file_exists($target)) {
            // Linked by an attempt that stopped before its commit
            if ($this->sameFile($source, $target)) {
                return self::LINKED;
            }
            if (is_file($target) && filesize($target) === filesize($source)) {
                throw FileMoveFailure::skip('already at target');
            }
            throw new FileMoveFailure('conflict: the target exists');
        }

        $directory = \dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (!$this->alwaysCopy && $this->sameFilesystem($source, $directory) && @link($source, $target)) {
            return self::LINKED;
        }

        $this->copy($source, $target, $verifyChecksum);

        return self::COPIED;
    }

    /** Removes the source, once the new location is committed */
    public function finish(string $source): void
    {
        if (is_file($source) && !@unlink($source)) {
            throw new \RuntimeException(sprintf('Moved, but unable to remove the source "%s".', $source));
        }
    }

    /** Reverts a transfer whose new location couldn't be committed: the source stays the file of record */
    public function undo(string $source, string $target): void
    {
        if (is_file($target) && is_file($source)) {
            @unlink($target);
        }
    }

    private function sameFile(string $a, string $b): bool
    {
        $statA = @stat($a);
        $statB = @stat($b);

        return false !== $statA && false !== $statB && $statA['dev'] === $statB['dev'] && $statA['ino'] === $statB['ino'];
    }

    private function sameFilesystem(string $source, string $targetDirectory): bool
    {
        $sourceStat = @stat($source);
        $targetStat = @stat($targetDirectory);

        return false !== $sourceStat && false !== $targetStat && $sourceStat['dev'] === $targetStat['dev'];
    }

    private function copy(string $source, string $target, bool $verifyChecksum): void
    {
        $part = $target.self::PART_SUFFIX;
        // Left over by an interrupted attempt
        if (file_exists($part)) {
            @unlink($part);
        }

        $in = @fopen($source, 'rb');
        $out = @fopen($part, 'xb');
        if (false === $in || false === $out) {
            \is_resource($in) && fclose($in);
            \is_resource($out) && fclose($out);
            throw new \RuntimeException(sprintf('Unable to open "%s" or "%s".', $source, $part));
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if (false === $chunk || ('' !== $chunk && false === fwrite($out, $chunk))) {
                    throw new \RuntimeException(sprintf('Copying "%s" failed.', $source));
                }
            }
            if (!fflush($out) || !fsync($out)) {
                throw new \RuntimeException(sprintf('Unable to flush "%s".', $part));
            }
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($part);
            throw $e;
        }
        fclose($in);
        fclose($out);

        clearstatcache();
        if (filesize($part) !== filesize($source)) {
            @unlink($part);
            throw new \RuntimeException(sprintf('The copy of "%s" has another size.', $source));
        }
        if ($verifyChecksum && hash_file('sha256', $part) !== hash_file('sha256', $source)) {
            @unlink($part);
            throw new \RuntimeException(sprintf('The copy of "%s" has another SHA-256.', $source));
        }

        // Never replace a file that appeared meanwhile: link() fails if the target exists, unlike rename()
        if (!@link($part, $target)) {
            if (file_exists($target)) {
                @unlink($part);
                throw new FileMoveFailure('conflict: the target exists');
            }
            if (!@rename($part, $target)) {
                @unlink($part);
                throw new \RuntimeException(sprintf('Unable to rename "%s".', $part));
            }

            return;
        }
        @unlink($part);
    }
}
