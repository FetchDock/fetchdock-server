<?php

namespace App\State\Settings;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Factory\DownloaderFactory;
use App\Service\Downloader\Options\OptionsValidator;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Rejects settings writes with downloader options that aren't in the downloader's options schema (422).
 */
final readonly class DownloaderOptionsGuard
{
    public function __construct(
        private OptionsValidator $optionsValidator,
        private DownloaderFactory $downloaderFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $options normalized options of one downloader
     */
    public function assertValid(string $downloader, array $options, object $root, string $propertyPath): void
    {
        $violations = new ConstraintViolationList();
        $this->collect($violations, $downloader, $options, $root, $propertyPath);
        self::throwIfAny($violations);
    }

    /**
     * @param array<array-key, mixed> $optionsByDownloader normalized options by downloader identifier
     */
    public function assertValidByDownloader(array $optionsByDownloader, object $root, string $propertyPath): void
    {
        $violations = new ConstraintViolationList();
        foreach ($optionsByDownloader as $downloader => $options) {
            $path = sprintf('%s[%s]', $propertyPath, $downloader);
            if (!$this->downloaderFactory->isValidDownloader((string) $downloader)) {
                $violations->add(self::violation(sprintf('The string "%s" is not a valid downloader.', $downloader), $root, $path, $downloader));
            } elseif (!is_array($options)) {
                $violations->add(self::violation('This value should be of type object.', $root, $path, $options));
            } else {
                $this->collect($violations, (string) $downloader, $options, $root, $path);
            }
        }
        self::throwIfAny($violations);
    }

    private function collect(ConstraintViolationList $violations, string $downloader, array $options, object $root, string $propertyPath): void
    {
        try {
            $errors = $this->optionsValidator->validate($downloader, $options);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(30, 'Unable to validate the downloader options right now.', $e);
        }

        foreach ($errors as $key => $message) {
            $violations->add(self::violation($message, $root, sprintf('%s[%s]', $propertyPath, $key), $options[$key] ?? null));
        }
    }

    private static function violation(string $message, object $root, string $path, mixed $value): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], $root, $path, $value);
    }

    private static function throwIfAny(ConstraintViolationList $violations): void
    {
        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }
    }
}
