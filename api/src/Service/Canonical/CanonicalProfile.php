<?php

namespace App\Service\Canonical;

/**
 * How URLs of a site are turned into canonical keys (see CanonicalKeyGenerator).
 *
 * Profiles are defined in code (config/packages/url_canonicalization.yaml); every profile extends
 * the "default" one, and sites can adjust theirs (config/sites.yaml "canonicalization:").
 */
final readonly class CanonicalProfile
{
    /** Fields sites may set, besides "profile" (the base profile name). pathRewrites are code only. */
    public const array OVERRIDABLE_FIELDS = ['useExtractorId', 'keepParams', 'dropParams', 'stripWww', 'stripTrailingSlash', 'idGroups'];
    public const array CODE_FIELDS = [...self::OVERRIDABLE_FIELDS, 'pathRewrites'];

    /**
     * @param list<string>|null                                 $keepParams   query parameter globs to keep; null keeps all but the dropped ones
     * @param list<string>                                      $dropParams   query parameter globs to drop (added to the default profile's)
     * @param list<array{pattern: string, replacement: string}> $pathRewrites regular expression replacements applied to the path
     * @param array<string, list<int>>                          $idGroups     gallery-dl subcategory ("*" for any) => indexes of the URL pattern groups forming the ID
     */
    public function __construct(
        public string $name,
        /** Whether the extractor identity (yt-dlp ID, gallery-dl groups) wins over the URL */
        public bool $useExtractorId = true,
        public ?array $keepParams = null,
        public array $dropParams = [],
        public bool $stripWww = false,
        public bool $stripTrailingSlash = false,
        public array $pathRewrites = [],
        public array $idGroups = [],
    ) {
    }

    /**
     * A copy with the given fields replaced, validated. dropParams are added to the current ones.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $allowedFields
     */
    public function with(array $fields, string $name, array $allowedFields = self::OVERRIDABLE_FIELDS): self
    {
        $unknown = array_diff(array_keys($fields), $allowedFields);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown canonicalization fields "%s"; allowed: %s.', implode('", "', $unknown), implode(', ', $allowedFields)));
        }

        $fields = array_filter($fields, static fn (mixed $value): bool => null !== $value);

        return new self(
            $name,
            self::bool($fields, 'useExtractorId') ?? $this->useExtractorId,
            array_key_exists('keepParams', $fields) ? self::strings($fields['keepParams'], 'keepParams') : $this->keepParams,
            array_values(array_unique([...$this->dropParams, ...self::strings($fields['dropParams'] ?? [], 'dropParams')])),
            self::bool($fields, 'stripWww') ?? $this->stripWww,
            self::bool($fields, 'stripTrailingSlash') ?? $this->stripTrailingSlash,
            array_key_exists('pathRewrites', $fields) ? self::pathRewrites($fields['pathRewrites']) : $this->pathRewrites,
            array_key_exists('idGroups', $fields) ? self::idGroups($fields['idGroups']) : $this->idGroups,
        );
    }

    /**
     * The gallery-dl URL pattern groups that identify the media, as selected by idGroups.
     *
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public function selectGroups(string $subcategory, array $groups): array
    {
        $indexes = $this->idGroups[$subcategory] ?? $this->idGroups['*'] ?? null;
        if (null === $indexes) {
            return $groups;
        }

        return array_values(array_map(static fn (int $index): string => $groups[$index] ?? '', $indexes));
    }

    private static function bool(array $fields, string $field): ?bool
    {
        if (!array_key_exists($field, $fields)) {
            return null;
        }
        if (!is_bool($fields[$field])) {
            throw new \InvalidArgumentException(sprintf('Canonicalization field "%s" must be a boolean.', $field));
        }

        return $fields[$field];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $item): bool => !is_string($item) || '' === $item)) {
            throw new \InvalidArgumentException(sprintf('Canonicalization field "%s" must be a list of non-empty strings.', $field));
        }

        return $value;
    }

    /**
     * @return list<array{pattern: string, replacement: string}>
     */
    private static function pathRewrites(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Canonicalization field "pathRewrites" must be a list.');
        }
        foreach ($value as $rewrite) {
            if (!is_string($rewrite['pattern'] ?? null) || !is_string($rewrite['replacement'] ?? null) || false === @preg_match($rewrite['pattern'], '')) {
                throw new \InvalidArgumentException('Each pathRewrites entry needs a valid regular expression "pattern" and a "replacement".');
            }
        }

        return array_map(static fn (array $rewrite): array => ['pattern' => $rewrite['pattern'], 'replacement' => $rewrite['replacement']], $value);
    }

    /**
     * @return array<string, list<int>>
     */
    private static function idGroups(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Canonicalization field "idGroups" must map gallery-dl subcategories to lists of group indexes.');
        }
        foreach ($value as $subcategory => $indexes) {
            if (!is_string($subcategory) || !is_array($indexes) || !array_is_list($indexes) || [] === $indexes
                || [] !== array_filter($indexes, static fn (mixed $index): bool => !is_int($index) || $index < 0)) {
                throw new \InvalidArgumentException('Canonicalization field "idGroups" must map gallery-dl subcategories to lists of group indexes.');
            }
        }

        return $value;
    }
}
