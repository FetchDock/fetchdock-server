<?php

namespace App\Entity;

use App\Repository\SiteCanonicalizationOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin override of a site's canonicalization profile (#94), applied on top of the code profiles
 * (config/packages/url_canonicalization.yaml) and the site's config/sites.yaml settings.
 * Every field is nullable: null inherits. Managed through /admin/sites/{slug}/canonicalization.
 */
#[ORM\Entity(repositoryClass: SiteCanonicalizationOverrideRepository::class)]
class SiteCanonicalizationOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'canonicalizationOverride')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Site $site = null;

    /** Name of the code profile to start from */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $profile = null;

    #[ORM\Column(nullable: true)]
    private ?bool $useExtractorId = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $keepParams = null;

    /** @var list<string>|null added to the inherited ones */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $dropParams = null;

    #[ORM\Column(nullable: true)]
    private ?bool $stripWww = null;

    #[ORM\Column(nullable: true)]
    private ?bool $stripTrailingSlash = null;

    /** @var array<string, list<int>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $idGroups = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function setProfile(?string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getUseExtractorId(): ?bool
    {
        return $this->useExtractorId;
    }

    public function setUseExtractorId(?bool $useExtractorId): static
    {
        $this->useExtractorId = $useExtractorId;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getKeepParams(): ?array
    {
        return $this->keepParams;
    }

    /**
     * @param list<string>|null $keepParams
     */
    public function setKeepParams(?array $keepParams): static
    {
        $this->keepParams = $keepParams;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getDropParams(): ?array
    {
        return $this->dropParams;
    }

    /**
     * @param list<string>|null $dropParams
     */
    public function setDropParams(?array $dropParams): static
    {
        $this->dropParams = $dropParams;

        return $this;
    }

    public function getStripWww(): ?bool
    {
        return $this->stripWww;
    }

    public function setStripWww(?bool $stripWww): static
    {
        $this->stripWww = $stripWww;

        return $this;
    }

    public function getStripTrailingSlash(): ?bool
    {
        return $this->stripTrailingSlash;
    }

    public function setStripTrailingSlash(?bool $stripTrailingSlash): static
    {
        $this->stripTrailingSlash = $stripTrailingSlash;

        return $this;
    }

    /**
     * @return array<string, list<int>>|null
     */
    public function getIdGroups(): ?array
    {
        return $this->idGroups;
    }

    /**
     * @param array<string, list<int>>|null $idGroups
     */
    public function setIdGroups(?array $idGroups): static
    {
        $this->idGroups = $idGroups;

        return $this;
    }

    /**
     * The overridden fields, in the format of Site::getCanonicalization() (null fields left out).
     *
     * @return array<string, mixed>
     */
    public function toSettings(): array
    {
        return array_filter([
            'profile' => $this->profile,
            'useExtractorId' => $this->useExtractorId,
            'keepParams' => $this->keepParams,
            'dropParams' => $this->dropParams,
            'stripWww' => $this->stripWww,
            'stripTrailingSlash' => $this->stripTrailingSlash,
            'idGroups' => $this->idGroups,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
