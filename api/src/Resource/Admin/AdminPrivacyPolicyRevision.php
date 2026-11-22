<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\PrivacyPolicyRevision;
use App\Enum\PrivacyPolicyRevisionReason;
use App\State\Admin\AdminPrivacyPolicyRevisionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A published revision of the generated privacy policy (ADR 0013). Revisions are never changed; the public page shows
 * them at /privacy?revision=N.
 */
#[ApiResource(
    shortName: 'AdminPrivacyPolicyRevision',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/privacy_policy/revisions',
            paginationEnabled: false,
            description: 'All revisions, newest first, without their text.',
            normalizationContext: ['groups' => ['privacy_policy_revision:read'], 'skip_null_values' => false],
        ),
        new Get(
            uriTemplate: '/admin/privacy_policy/revisions/{number}',
            requirements: ['number' => '\d+'],
            description: 'One revision with its text (Markdown and HTML).',
            normalizationContext: ['groups' => ['privacy_policy_revision:read', 'privacy_policy_revision:text'], 'skip_null_values' => false],
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminPrivacyPolicyRevisionProvider::class,
)]
final class AdminPrivacyPolicyRevision
{
    #[ApiProperty(identifier: true)]
    #[Groups(['privacy_policy_revision:read'])]
    public int $number = 0;

    #[Groups(['privacy_policy_revision:read'])]
    public PrivacyPolicyRevisionReason $reason = PrivacyPolicyRevisionReason::INITIAL;

    #[Groups(['privacy_policy_revision:read'])]
    public string $textVersion = '';

    #[Groups(['privacy_policy_revision:read'])]
    public string $appVersion = '';

    #[Groups(['privacy_policy_revision:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    /** Whether this is the revision in force (false while no generated policy is published) */
    #[Groups(['privacy_policy_revision:read'])]
    public bool $current = false;

    #[Groups(['privacy_policy_revision:text'])]
    public string $markdown = '';

    /** An HTML fragment */
    #[Groups(['privacy_policy_revision:text'])]
    public string $html = '';

    public static function fromEntity(PrivacyPolicyRevision $revision, bool $current): self
    {
        $resource = new self();
        $resource->number = $revision->getNumber();
        $resource->reason = $revision->getReason();
        $resource->textVersion = $revision->getTextVersion();
        $resource->appVersion = $revision->getAppVersion();
        $resource->createdAt = $revision->getCreatedAt();
        $resource->current = $current;
        $resource->markdown = $revision->getMarkdown();
        $resource->html = $revision->getHtml();

        return $resource;
    }
}
