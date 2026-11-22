<?php

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Optional details of a failed job, each one opted in by the reporter and stored as sent (ADR 0012).
 */
final class FailureReportDiagnostics
{
    /** The job's downloader, e.g. yt-dlp-cli */
    #[Assert\Length(max: 100)]
    #[Groups(['failure_report:read', 'failure_report:write', 'admin_failure_report:read'])]
    public ?string $downloader = null;

    #[Assert\Length(max: 100)]
    #[Groups(['failure_report:read', 'failure_report:write', 'admin_failure_report:read'])]
    public ?string $downloaderVersion = null;

    /** The error message from the job's events, verbatim */
    #[Assert\Length(max: 4000)]
    #[Groups(['failure_report:read', 'failure_report:write', 'admin_failure_report:read'])]
    public ?string $errorMessage = null;

    /** Version of the client that sent the report */
    #[Assert\Length(max: 100)]
    #[Groups(['failure_report:read', 'failure_report:write', 'admin_failure_report:read'])]
    public ?string $clientVersion = null;

    /**
     * @param array<string, string|null> $data
     */
    public static function fromArray(array $data): self
    {
        $diagnostics = new self();
        $diagnostics->downloader = $data['downloader'] ?? null;
        $diagnostics->downloaderVersion = $data['downloaderVersion'] ?? null;
        $diagnostics->errorMessage = $data['errorMessage'] ?? null;
        $diagnostics->clientVersion = $data['clientVersion'] ?? null;

        return $diagnostics;
    }

    /**
     * @return array<string, string> the fields that were sent
     */
    public function toArray(): array
    {
        return array_filter([
            'downloader' => $this->downloader,
            'downloaderVersion' => $this->downloaderVersion,
            'errorMessage' => $this->errorMessage,
            'clientVersion' => $this->clientVersion,
        ], static fn (?string $value): bool => null !== $value);
    }
}
