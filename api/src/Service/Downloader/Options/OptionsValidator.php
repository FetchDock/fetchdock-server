<?php

namespace App\Service\Downloader\Options;

/**
 * Validates a downloader options object against the options users may configure
 * (the downloader's options schema): unknown or hidden keys are rejected.
 */
class OptionsValidator
{
    public function __construct(
        private readonly OptionsSchemaFactory $schemaFactory,
    ) {
    }

    /**
     * Drops null values (null = inherit, as for every settings field).
     *
     * @param array<string, mixed>|null $options
     *
     * @return array<string, mixed>
     */
    public static function normalize(?array $options): array
    {
        return array_filter($options ?? [], static fn ($value) => null !== $value);
    }

    /**
     * @param array<string, mixed> $options normalized options
     *
     * @return array<string, string> violation message by option key, empty if valid
     *
     * @throws \RuntimeException when the tool's options can't be listed
     */
    public function validate(string $downloader, array $options): array
    {
        if ([] === $options) {
            return [];
        }

        $exposed = $this->schemaFactory->getExposedOptions($downloader);

        $violations = [];
        foreach ($options as $key => $value) {
            $option = $exposed[$key] ?? null;
            $error = null === $option ? sprintf('The option "%s" is unknown or not configurable for %s.', $key, $downloader) : self::validateValue($option, $value);
            if (null !== $error) {
                $violations[(string) $key] = $error;
            }
        }

        return $violations;
    }

    public static function isValid(OptionDefinition $option, mixed $value): bool
    {
        return null === self::validateValue($option, $value);
    }

    private static function validateValue(OptionDefinition $option, mixed $value): ?string
    {
        if (OptionDefinition::KIND_APPEND !== $option->kind) {
            return self::validateScalar($option, $value);
        }

        if (!is_array($value) || !array_is_list($value)) {
            return 'This value should be a list.';
        }
        if (count($value) > OptionsSchemaFactory::MAX_ITEMS) {
            return sprintf('This list should contain %d items or less.', OptionsSchemaFactory::MAX_ITEMS);
        }
        foreach ($value as $item) {
            $error = self::validateScalar($option, $item);
            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    private static function validateScalar(OptionDefinition $option, mixed $value): ?string
    {
        $valid = match ($option->type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            default => is_string($value),
        };
        if (!$valid) {
            return sprintf('This value should be of type %s.', $option->type);
        }

        if (is_string($value)) {
            if (mb_strlen($value) > OptionsSchemaFactory::MAX_STRING_LENGTH) {
                return sprintf('This value is too long. It should have %d characters or less.', OptionsSchemaFactory::MAX_STRING_LENGTH);
            }
            if (1 === preg_match('/[\x00-\x1f\x7f]/', $value) || !mb_check_encoding($value, 'UTF-8')) {
                return 'This value contains invalid characters.';
            }
        }

        if (null !== $option->choices && !in_array($value, $option->choices, true)) {
            return sprintf('This value should be one of: %s.', implode(', ', array_map('strval', $option->choices)));
        }

        return null;
    }
}
