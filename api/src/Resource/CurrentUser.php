<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\CurrentUserProvider;

/**
 * The authenticated user, with their effective roles, so clients can show only what the user may do.
 */
#[ApiResource(
    shortName: 'CurrentUser',
    operations: [
        new Get(
            uriTemplate: '/users/me',
            description: 'The authenticated user and their effective roles (including the roles granted through the role hierarchy).',
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    provider: CurrentUserProvider::class,
)]
final class CurrentUser
{
    /** The user's OIDC subject */
    #[ApiProperty(identifier: true)]
    public string $subject;

    /** The user's UUID (also used in their Mercure topics); null until the user is known to the server */
    public ?string $uuid = null;

    /**
     * Effective roles, sorted, e.g. ["ROLE_USER", "ROLE_VIEW_ALL_DOWNLOADS"]. Permission roles for the
     * /admin/... operations: ROLE_VIEW_ALL_DOWNLOADS (view) and ROLE_MANAGE_ALL_DOWNLOADS (restore, delete).
     *
     * @var list<string>
     */
    public array $roles = [];
}
