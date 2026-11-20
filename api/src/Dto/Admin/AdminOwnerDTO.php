<?php

namespace App\Dto\Admin;

use App\Entity\OidcSubjectIdentifier;

/**
 * The owner of a download job, as shown to admins.
 */
final readonly class AdminOwnerDTO
{
    public function __construct(
        /** The user's UUID (also used in their Mercure topics) */
        public string $uuid,
        /** The user's OIDC subject */
        public string $subject,
    ) {
    }

    public static function fromEntity(?OidcSubjectIdentifier $owner): ?self
    {
        return null !== $owner ? new self((string) $owner->getUuid()?->toRfc4122(), (string) $owner->getSubject()) : null;
    }
}
