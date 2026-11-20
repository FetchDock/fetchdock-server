<?php

namespace App\Service\Downloader\Options;

/**
 * One configurable command line option of a downloader, as a property of its options schema.
 */
final readonly class OptionDefinition
{
    public const string KIND_BOOLEAN = 'boolean';
    public const string KIND_VALUE = 'value';
    public const string KIND_APPEND = 'append';

    public function __construct(
        /** Property key: the parser dest, or the flag without "--" for flags setting a constant */
        public string $key,
        /** self::KIND_* */
        public string $kind,
        /** JSON type of the value (of each item for KIND_APPEND); "boolean" for KIND_BOOLEAN */
        public string $type,
        /** Flag passed for a value, or for true. Null if true has no flag (keeps the default) */
        public ?string $flag,
        /** Flag passed for false (KIND_BOOLEAN only). Null if false has no flag (keeps the default) */
        public ?string $falseFlag,
        /** All flags of the parser options behind this property, for the deny lists */
        public array $flags,
        public string $group,
        public string $title,
        public ?string $description = null,
        public ?string $metavar = null,
        /** @var list<string|int|float>|null */
        public ?array $choices = null,
    ) {
    }

    public function withOverrides(?string $label, ?string $type): self
    {
        return new self(
            key: $this->key,
            kind: $this->kind,
            type: null !== $type && self::KIND_BOOLEAN !== $this->kind ? $type : $this->type,
            flag: $this->flag,
            falseFlag: $this->falseFlag,
            flags: $this->flags,
            group: $this->group,
            title: $label ?? $this->title,
            description: $this->description,
            metavar: $this->metavar,
            choices: null !== $type && $type !== $this->type ? null : $this->choices,
        );
    }
}
