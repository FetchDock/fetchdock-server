<?php

namespace App\Service\Downloader\Options;

use Psr\Log\LoggerInterface;

/**
 * Turns a downloader options object into command line arguments: every value is one
 * "--flag=value" argv element (never a separate value element, never a shell), booleans a bare
 * flag. Options that aren't configurable (anymore) or have an invalid value are skipped.
 */
class OptionsArgumentsBuilder
{
    public function __construct(
        private readonly OptionsSchemaFactory $schemaFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    public function build(string $downloader, array $options): array
    {
        $options = OptionsValidator::normalize($options);
        if ([] === $options) {
            return [];
        }

        try {
            $exposed = $this->schemaFactory->getExposedOptions($downloader);
        } catch (\RuntimeException $e) {
            $this->logger->error('Unable to list the downloader options, the user options are ignored', ['downloader' => $downloader, 'exception' => $e]);

            return [];
        }

        $skipped = array_diff_key($options, $exposed);
        $arguments = [];
        foreach ($exposed as $key => $option) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = $options[$key];
            if (!OptionsValidator::isValid($option, $value)) {
                $skipped[$key] = $value;
                continue;
            }

            array_push($arguments, ...self::arguments($option, $value));
        }

        if ([] !== $skipped) {
            $this->logger->warning('Skipped downloader options that are not configurable or invalid', ['downloader' => $downloader, 'options' => array_keys($skipped)]);
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    private static function arguments(OptionDefinition $option, mixed $value): array
    {
        if (OptionDefinition::KIND_BOOLEAN === $option->kind) {
            $flag = $value ? $option->flag : $option->falseFlag;

            return null === $flag ? [] : [$flag];
        }

        $values = OptionDefinition::KIND_APPEND === $option->kind ? $value : [$value];

        return array_map(static fn ($item) => $option->flag.'='.self::stringify($item), $values);
    }

    private static function stringify(string|int|float $value): string
    {
        return is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : (string) $value;
    }
}
