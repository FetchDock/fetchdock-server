<?php

namespace App\Service\FailureReport;

/**
 * The collector's privacy policy (GET {collectorUrl}/v1/policy): the current version, its page, and the versions under
 * which the collector still honours consent.
 */
final readonly class CollectorPolicy
{
    /**
     * @param list<string> $acceptedVersions
     */
    public function __construct(
        public string $version,
        public string $url,
        public array $acceptedVersions,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $version = $data['version'] ?? null;
        $url = $data['url'] ?? null;
        $accepted = $data['acceptedVersions'] ?? null;
        if (!\is_string($version) || '' === $version || !\is_string($url) || !preg_match('#^https?://#i', $url) || !\is_array($accepted)) {
            return null;
        }
        $accepted = array_values(array_filter($accepted, static fn (mixed $v): bool => \is_string($v) && '' !== $v));
        if (!\in_array($version, $accepted, true)) {
            $accepted[] = $version;
        }

        return new self($version, $url, $accepted);
    }

    /**
     * @return array{version: string, url: string, acceptedVersions: list<string>}
     */
    public function toArray(): array
    {
        return ['version' => $this->version, 'url' => $this->url, 'acceptedVersions' => $this->acceptedVersions];
    }

    public function accepts(?string $version): bool
    {
        return null !== $version && \in_array($version, $this->acceptedVersions, true);
    }
}
