<?php

namespace App\Dto\PrivacyPolicy;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How long the operator keeps data outside the application's own rules (AdminPrivacyPolicy).
 */
final class PrivacyPolicyRetention
{
    /** Days the operator keeps container and web server logs (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Range(min: 0, max: 36500)]
    public ?int $logsDays = null;

    /** How backups are made and kept, in a sentence */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 1000)]
    public ?string $backups = null;

    /** Days after which the operator erases jobs users deleted, if they do */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Range(min: 0, max: 36500)]
    public ?int $softDeletedJobsDays = null;

    /** Days after which the operator deletes failure reports, if they do */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Range(min: 0, max: 36500)]
    public ?int $reportsDays = null;
}
