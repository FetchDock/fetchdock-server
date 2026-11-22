<?php

namespace App\Service\Privacy;

/**
 * Formats the "Data practices changed" section of the release notes (ADR 0013) from the privacy policy text versions
 * added since the previous release, so operators know to review their policy answers or their own custom policy.
 */
final readonly class PolicyReleaseNotes
{
    public const string HEADING = '## Data practices changed';

    /**
     * @param list<array{version: string, digest: string, changes: string}>|null $previous versions in the previous
     *                                                                                     release, null when it predates policy-version.yaml
     * @param list<array{version: string, digest: string, changes: string}>      $current  versions in this release
     *
     * @return string Markdown, empty when no version was added
     */
    public function section(?array $previous, array $current): string
    {
        if ([] === $current) {
            throw new \InvalidArgumentException('This release lists no policy text versions');
        }
        $latest = $current[array_key_last($current)]['version'];

        if (null === $previous) {
            return self::HEADING."\n\n"
                .\sprintf('FetchDock can now generate this instance\'s privacy policy (policy text version %s) from the data it stores, its configuration and the answers an admin enters under *Admin → Privacy policy*. ', $latest)
                .'It is published at `/privacy` while the instance\'s code reads as verified. '
                .'If you link your own policy through `PRIVACY_POLICY_URL`, that one stays in use.'."\n";
        }

        foreach ($previous as $index => $entry) {
            $now = $current[$index] ?? null;
            if (null === $now || $now['version'] !== $entry['version'] || $now['digest'] !== $entry['digest']) {
                throw new \UnexpectedValueException(\sprintf('Policy text version %s of the previous release was edited or removed. Versions are only ever appended to %s', $entry['version'], PolicyTextVersion::VERSION_FILE));
            }
        }

        $added = \array_slice($current, \count($previous));
        if ([] === $added) {
            return '';
        }

        $from = [] === $previous ? null : $previous[array_key_last($previous)]['version'];
        $lines = [self::HEADING, ''];
        $lines[] = null === $from
            ? \sprintf('The data inventory or the generated privacy policy text changed (policy text version %s):', $latest)
            : \sprintf('The data inventory or the generated privacy policy text changed (policy text version %s → %s):', $from, $latest);
        foreach ($added as $entry) {
            $changes = trim($entry['changes']);
            $lines[] = \sprintf('- **%s:** %s', $entry['version'], '' === $changes ? 'no description' : $changes);
        }
        $lines[] = '';
        if (null !== $from && '0' !== self::major($from) && self::major($from) !== self::major($latest)) {
            $lines[] = 'This is a major change: what the server does with personal data changed.';
            $lines[] = '';
        }
        $lines[] = 'If the instance publishes the generated policy, review your answers under *Admin → Privacy policy*: the server publishes a new revision after the upgrade. '
            .'If you link your own policy through `PRIVACY_POLICY_URL`, check that it still matches.';

        return implode("\n", $lines)."\n";
    }

    private static function major(string $version): string
    {
        return explode('.', $version, 2)[0];
    }
}
