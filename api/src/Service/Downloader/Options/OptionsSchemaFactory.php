<?php

namespace App\Service\Downloader\Options;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Builds the options schema of a downloader from its tool's option parser (bin/dump_options.py)
 * and the options policy.
 *
 * Properties are keyed by the parser "dest". Flags storing true/false into the same dest become one
 * boolean property (x-flag for true, x-flag-false for false); flags setting another constant become a
 * boolean property keyed by the flag without "--". Value options use their first long flag.
 *
 * The tool's options are cached per tool version; the policy (incl. admin overrides) is applied on
 * every call, so policy changes apply at once.
 */
class OptionsSchemaFactory
{
    /**
     * Downloader identifier => tool name used by bin/dump_options.py.
     */
    public const array TOOLS = [
        'gallery-dl-cli' => 'gallery-dl',
        'yt-dlp-cli' => 'yt-dlp',
    ];

    public const string CACHE_TAG = 'downloader_options';
    public const int MAX_STRING_LENGTH = 1024;
    public const int MAX_ITEMS = 20;

    private const int CACHE_TTL = 3600;

    public function __construct(
        private readonly OptionsDumperInterface $dumper,
        private readonly TagAwareCacheInterface $cache,
        private readonly OptionsPolicy $policy,
    ) {
    }

    /**
     * Whether the downloader has configurable options at all.
     */
    public function hasOptions(string $downloader): bool
    {
        return isset(self::TOOLS[$downloader]);
    }

    /**
     * All options of the downloader's tool, before the policy, by key.
     *
     * @return array<string, OptionDefinition>
     *
     * @throws \RuntimeException when the tool's options can't be listed
     */
    public function getAllOptions(string $downloader): array
    {
        $dump = $this->getDump($downloader);

        return null === $dump ? [] : self::buildDefinitions($dump['options']);
    }

    /**
     * The options users may configure, by key.
     *
     * @return array<string, OptionDefinition>
     *
     * @throws \RuntimeException when the tool's options can't be listed
     */
    public function getExposedOptions(string $downloader): array
    {
        return $this->policy->filter($downloader, $this->getAllOptions($downloader));
    }

    /**
     * The installed version of the downloader's tool, null for downloaders without options.
     *
     * @throws \RuntimeException when the tool's options can't be listed
     */
    public function getToolVersion(string $downloader): ?string
    {
        return $this->getDump($downloader)['version'] ?? null;
    }

    /**
     * JSON Schema (draft 2020-12) of the options object users may configure for the downloader.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the tool's options can't be listed
     */
    public function getSchema(string $downloader): array
    {
        $properties = [];
        foreach ($this->getExposedOptions($downloader) as $key => $option) {
            $properties[$key] = self::propertySchema($option);
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => sprintf('/downloaders/%s/options-schema', $downloader),
            'title' => sprintf('%s options', $downloader),
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'x-tool-version' => $this->getToolVersion($downloader),
        ];
    }

    /**
     * Forget the cached tool options, e.g. after a tool update.
     */
    public function invalidate(): void
    {
        $this->cache->invalidateTags([self::CACHE_TAG]);
    }

    /**
     * @return array{version: string, options: list<array<string, mixed>>}|null
     */
    private function getDump(string $downloader): ?array
    {
        $tool = self::TOOLS[$downloader] ?? null;
        if (null === $tool) {
            return null;
        }

        return $this->cache->get('downloader_options.'.$tool, function (ItemInterface $item) use ($tool) {
            $item->tag([self::CACHE_TAG]);
            $item->expiresAfter(self::CACHE_TTL);

            return $this->dumper->dump($tool);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function propertySchema(OptionDefinition $option): array
    {
        $valueSchema = ['type' => $option->type];
        if ('string' === $option->type) {
            $valueSchema['maxLength'] = self::MAX_STRING_LENGTH;
        }
        if (null !== $option->choices) {
            $valueSchema['enum'] = $option->choices;
        }

        $schema = match ($option->kind) {
            OptionDefinition::KIND_APPEND => ['type' => 'array', 'items' => $valueSchema, 'maxItems' => self::MAX_ITEMS],
            default => $valueSchema,
        };
        $schema['title'] = $option->title;
        if (null !== $option->description) {
            $schema['description'] = $option->description;
        }
        if (null !== $option->flag) {
            $schema['x-flag'] = $option->flag;
        }
        if (null !== $option->falseFlag) {
            $schema['x-flag-false'] = $option->falseFlag;
        }
        if (null !== $option->metavar) {
            $schema['x-metavar'] = $option->metavar;
        }
        $schema['x-group'] = $option->group;

        return $schema;
    }

    /**
     * @param list<array<string, mixed>> $dumpedOptions
     *
     * @return array<string, OptionDefinition>
     */
    public static function buildDefinitions(array $dumpedOptions): array
    {
        $usable = [];
        foreach ($dumpedOptions as $option) {
            $flags = array_values(array_filter($option['flags'] ?? [], 'is_string'));
            $longFlag = array_values(array_filter($flags, static fn (string $flag) => str_starts_with($flag, '--')))[0] ?? null;
            if (true === ($option['hidden'] ?? false) || null === $longFlag || !in_array($option['kind'] ?? null, ['value', 'append', 'true', 'false', 'const'], true)) {
                continue;
            }
            $usable[] = $option + ['longFlag' => $longFlag, 'flags' => $flags];
        }

        // Options storing into the same dest, in the order of their first option
        $byDest = [];
        foreach ($usable as $option) {
            if ('const' !== $option['kind'] && is_string($option['dest'] ?? null) && '' !== $option['dest']) {
                $byDest[$option['dest']][] = $option;
            }
        }

        $definitions = [];
        foreach ($usable as $option) {
            if ('const' === $option['kind']) {
                $key = substr($option['longFlag'], 2);
                if (!isset($byDest[$key]) && !isset($definitions[$key])) {
                    $definitions[$key] = self::booleanDefinition($key, $option, null);
                }
                continue;
            }

            $dest = $option['dest'] ?? null;
            if (!isset($byDest[$dest]) || isset($definitions[$dest])) {
                continue;
            }
            $definition = self::destDefinition($dest, $byDest[$dest]);
            if (null !== $definition) {
                $definitions[$dest] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $options
     */
    private static function destDefinition(string $dest, array $options): ?OptionDefinition
    {
        foreach ($options as $option) {
            if (in_array($option['kind'], ['value', 'append'], true)) {
                $choices = is_array($option['choices'] ?? null) ? array_values(array_filter($option['choices'], static fn ($choice) => is_string($choice) || is_int($choice) || is_float($choice))) : null;

                return new OptionDefinition(
                    key: $dest,
                    kind: 'append' === $option['kind'] ? OptionDefinition::KIND_APPEND : OptionDefinition::KIND_VALUE,
                    type: in_array($option['type'] ?? null, ['integer', 'number'], true) ? $option['type'] : 'string',
                    flag: $option['longFlag'],
                    falseFlag: null,
                    flags: $option['flags'],
                    group: (string) ($option['group'] ?? ''),
                    title: self::title($option['longFlag']),
                    description: self::description($option['help'] ?? null),
                    metavar: is_string($option['metavar'] ?? null) ? $option['metavar'] : null,
                    choices: [] === $choices ? null : $choices,
                );
            }
        }

        $true = array_values(array_filter($options, static fn ($option) => 'true' === $option['kind']))[0] ?? null;
        $false = array_values(array_filter($options, static fn ($option) => 'false' === $option['kind']))[0] ?? null;

        return null === $true && null === $false ? null : self::booleanDefinition($dest, $true, $false);
    }

    private static function booleanDefinition(string $key, ?array $true, ?array $false): OptionDefinition
    {
        $description = self::description($true['help'] ?? null);
        if (null === $true) {
            $falseHelp = self::description($false['help'] ?? null);
            $description = null === $falseHelp ? null : 'false: '.$falseHelp;
        }

        return new OptionDefinition(
            key: $key,
            kind: OptionDefinition::KIND_BOOLEAN,
            type: 'boolean',
            flag: $true['longFlag'] ?? null,
            falseFlag: $false['longFlag'] ?? null,
            flags: array_values(array_unique(array_merge($true['flags'] ?? [], $false['flags'] ?? []))),
            group: (string) ($true['group'] ?? $false['group'] ?? ''),
            title: null !== $true ? self::title($true['longFlag']) : ucfirst(str_replace(['-', '_'], ' ', $key)),
            description: $description,
            metavar: is_string($true['metavar'] ?? null) ? $true['metavar'] : null,
        );
    }

    private static function title(string $longFlag): string
    {
        return ucfirst(str_replace('-', ' ', substr($longFlag, 2)));
    }

    private static function description(mixed $help): ?string
    {
        if (!is_string($help)) {
            return null;
        }
        $help = trim((string) preg_replace('/\s+/', ' ', $help));

        return '' === $help ? null : $help;
    }
}
