<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\AccountDeleteProcessor;
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
        new Delete(
            uriTemplate: '/users/me',
            description: 'Deletes the authenticated user\'s account and its data: download jobs, settings, clients, client preferences and failure reports. Files of the deleted jobs that no other user\'s job uses are deleted from disk afterwards. The identity provider account stays, so signing in again creates a new, empty account. Publishes {"type":"account_deleted"} on the user\'s private Mercure topic /users/{uuid}/account, so other signed-in clients can sign out.',
            parameters: [
                'confirm' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'The user\'s subject (from GET /users/me), to confirm the deletion; 400 otherwise',
                    required: true,
                ),
            ],
            processor: AccountDeleteProcessor::class,
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
