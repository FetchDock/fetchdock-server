<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Entity\Site;
use App\Entity\SiteCanonicalizationOverride;
use App\Service\Canonical\CanonicalProfile;
use App\State\Admin\AdminSiteCanonicalizationProcessor;
use App\State\Admin\AdminSiteCanonicalizationProvider;
use App\Validator\ValidCanonicalization;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A site's admin override of its canonicalization profile (duplicate detection, #94). Null = inherit
 * from the site's config/sites.yaml settings and the code profiles. Path rewrites are code only.
 */
#[ApiResource(
    shortName: 'AdminSiteCanonicalization',
    operations: [
        new Get(uriTemplate: '/admin/sites/{slug}/canonicalization'),
        new Put(
            uriTemplate: '/admin/sites/{slug}/canonicalization',
            description: "Replaces the site's canonicalization override; omitted or null fields inherit. Takes effect for new submissions.",
            extraProperties: ['standard_put' => true],
        ),
        new Delete(uriTemplate: '/admin/sites/{slug}/canonicalization', description: "Removes the site's canonicalization override (all fields inherit)."),
    ],
    normalizationContext: ['groups' => ['site_canonicalization:read'], 'skip_null_values' => false, 'preserve_empty_objects' => true],
    // Unknown fields (e.g. pathRewrites, which are code only) are rejected
    denormalizationContext: ['groups' => ['site_canonicalization:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminSiteCanonicalizationProvider::class,
    processor: AdminSiteCanonicalizationProcessor::class,
)]
#[ValidCanonicalization]
final class AdminSiteCanonicalization
{
    public const int MAX_PATTERNS = 50;

    /** The site's slug */
    #[ApiProperty(identifier: true)]
    #[Groups(['site_canonicalization:read'])]
    public string $slug;

    /** The site's name */
    #[Groups(['site_canonicalization:read'])]
    public string $name;

    /** Name of the code profile to start from (see availableProfiles) */
    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?string $profile = null;

    /** Whether the extractor identity (yt-dlp ID, gallery-dl groups) wins over the URL */
    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?bool $useExtractorId = null;

    /**
     * Query parameter globs to keep; all others are dropped.
     *
     * @var list<string>|null
     */
    #[Assert\Count(max: self::MAX_PATTERNS)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 100)])]
    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?array $keepParams = null;

    /**
     * Query parameter globs to drop, added to the inherited ones.
     *
     * @var list<string>|null
     */
    #[Assert\Count(max: self::MAX_PATTERNS)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 100)])]
    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?array $dropParams = null;

    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?bool $stripWww = null;

    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?bool $stripTrailingSlash = null;

    /**
     * gallery-dl subcategory ("*" for any) => indexes of the URL pattern groups identifying the media.
     *
     * @var array<string, list<int>>|null
     */
    #[Assert\Count(max: self::MAX_PATTERNS)]
    #[ApiProperty(openapiContext: ['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]]])]
    #[Groups(['site_canonicalization:read', 'site_canonicalization:write'])]
    public ?array $idGroups = null;

    /**
     * The site's settings from config/sites.yaml (read-only), null if none.
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(openapiContext: ['type' => ['object', 'null'], 'additionalProperties' => true])]
    #[Groups(['site_canonicalization:read'])]
    public ?array $siteSettings = null;

    /**
     * The profile in effect for the site, with this override applied (read-only).
     *
     * @var array<string, mixed>
     */
    #[ApiProperty(openapiContext: ['type' => 'object', 'additionalProperties' => true])]
    #[Groups(['site_canonicalization:read'])]
    public array $effective = [];

    /**
     * Names of the code profiles (read-only).
     *
     * @var list<string>
     */
    #[Groups(['site_canonicalization:read'])]
    public array $availableProfiles = [];

    /**
     * @param list<string> $availableProfiles
     */
    public static function fromSite(Site $site, CanonicalProfile $effective, array $availableProfiles): self
    {
        $override = $site->getCanonicalizationOverride();

        $resource = new self();
        $resource->slug = (string) $site->getSlug();
        $resource->name = (string) $site->getName();
        $resource->profile = $override?->getProfile();
        $resource->useExtractorId = $override?->getUseExtractorId();
        $resource->keepParams = $override?->getKeepParams();
        $resource->dropParams = $override?->getDropParams();
        $resource->stripWww = $override?->getStripWww();
        $resource->stripTrailingSlash = $override?->getStripTrailingSlash();
        $resource->idGroups = $override?->getIdGroups();
        $resource->siteSettings = $site->getCanonicalization();
        $resource->effective = [
            'profile' => $effective->name,
            'useExtractorId' => $effective->useExtractorId,
            'keepParams' => $effective->keepParams,
            'dropParams' => $effective->dropParams,
            'stripWww' => $effective->stripWww,
            'stripTrailingSlash' => $effective->stripTrailingSlash,
            'pathRewrites' => $effective->pathRewrites,
            'idGroups' => (object) $effective->idGroups,
        ];
        $resource->availableProfiles = $availableProfiles;

        return $resource;
    }

    /**
     * The overridden fields, in the format of Site::getCanonicalization() (null fields left out).
     *
     * @return array<string, mixed>
     */
    public function toSettings(): array
    {
        return $this->applyTo(new SiteCanonicalizationOverride())->toSettings();
    }

    public function applyTo(SiteCanonicalizationOverride $override): SiteCanonicalizationOverride
    {
        return $override
            ->setProfile($this->profile)
            ->setUseExtractorId($this->useExtractorId)
            ->setKeepParams(null === $this->keepParams ? null : array_values($this->keepParams))
            ->setDropParams(null === $this->dropParams ? null : array_values($this->dropParams))
            ->setStripWww($this->stripWww)
            ->setStripTrailingSlash($this->stripTrailingSlash)
            ->setIdGroups($this->idGroups);
    }
}
