<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Filter\SiteSearchFilter;
use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A website, possibly with several domains, handled by one or more downloader extractors.
 *
 * Curated sites come from config/sites.yaml (app:sites:sync); others are created automatically
 * when a URL resolves to an extractor that isn't mapped yet (one site per extractor family).
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['site:read']],
        ),
        new Get(
            requirements: ['slug' => '(?!resolve$)[a-z0-9][a-z0-9-]*'],
            normalizationContext: ['groups' => ['site:read', 'site:item']],
        ),
    ],
    security: "is_granted('ROLE_USER')",
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SiteSearchFilter::class, properties: ['search'])]
#[ApiFilter(BooleanFilter::class, properties: ['enabled'])]
class Site
{
    #[ApiProperty(identifier: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ApiProperty(identifier: true)]
    #[Groups(['site:read'])]
    #[ORM\Column(length: 64, unique: true)]
    private ?string $slug = null;

    #[Groups(['site:read'])]
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * Lowercase host names, e.g. ["x.com", "twitter.com"].
     *
     * @var list<string>
     */
    #[Groups(['site:read'])]
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $domains = [];

    #[Groups(['site:read'])]
    #[ORM\Column]
    private bool $enabled = true;

    /**
     * Whether the site is defined in config/sites.yaml (true) or was created automatically (false).
     */
    #[Groups(['site:read'])]
    #[ORM\Column]
    private bool $curated = false;

    /**
     * @var Collection<int, SiteExtractor>
     */
    #[ApiProperty(genId: false)]
    #[Groups(['site:item'])]
    #[ORM\OneToMany(targetEntity: SiteExtractor::class, mappedBy: 'site', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['downloader' => 'ASC', 'extractorKey' => 'ASC'])]
    private Collection $extractors;

    #[ORM\OneToOne(mappedBy: 'site', cascade: ['persist', 'remove'])]
    private ?SiteSetting $defaultSetting = null;

    /**
     * Canonicalization profile settings from config/sites.yaml ("profile" and the fields of
     * App\Service\Canonical\CanonicalProfile::OVERRIDABLE_FIELDS). Server-only.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $canonicalization = null;

    /** Admin override of the canonicalization settings, applied on top of $canonicalization. Server-only. */
    #[ORM\OneToOne(mappedBy: 'site', cascade: ['persist', 'remove'])]
    private ?SiteCanonicalizationOverride $canonicalizationOverride = null;

    public function __construct()
    {
        $this->extractors = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDomains(): array
    {
        return $this->domains;
    }

    /**
     * @param iterable<string> $domains
     */
    public function setDomains(iterable $domains): static
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ('' !== $domain) {
                $normalized[$domain] = true;
            }
        }
        $normalized = array_keys($normalized);
        sort($normalized);
        $this->domains = $normalized;

        return $this;
    }

    public function hasDomain(string $domain): bool
    {
        return in_array(strtolower($domain), $this->domains, true);
    }

    public function addDomain(string $domain): static
    {
        return $this->setDomains([...$this->domains, $domain]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isCurated(): bool
    {
        return $this->curated;
    }

    public function setCurated(bool $curated): static
    {
        $this->curated = $curated;

        return $this;
    }

    /**
     * @return Collection<int, SiteExtractor>
     */
    public function getExtractors(): Collection
    {
        return $this->extractors;
    }

    public function addExtractor(SiteExtractor $extractor): static
    {
        if (!$this->extractors->contains($extractor)) {
            $extractor->getSite()?->removeExtractor($extractor);
            $this->extractors->add($extractor);
            $extractor->setSite($this);
        }

        return $this;
    }

    public function removeExtractor(SiteExtractor $extractor): static
    {
        $this->extractors->removeElement($extractor);

        return $this;
    }

    public function getDefaultSetting(): ?SiteSetting
    {
        return $this->defaultSetting;
    }

    public function setDefaultSetting(SiteSetting $defaultSetting): static
    {
        if ($defaultSetting->getSite() !== $this) {
            $defaultSetting->setSite($this);
        }
        $this->defaultSetting = $defaultSetting;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCanonicalization(): ?array
    {
        return $this->canonicalization;
    }

    /**
     * @param array<string, mixed>|null $canonicalization
     */
    public function setCanonicalization(?array $canonicalization): static
    {
        $this->canonicalization = [] === $canonicalization ? null : $canonicalization;

        return $this;
    }

    public function getCanonicalizationOverride(): ?SiteCanonicalizationOverride
    {
        return $this->canonicalizationOverride;
    }

    public function setCanonicalizationOverride(?SiteCanonicalizationOverride $override): static
    {
        if (null !== $override && $override->getSite() !== $this) {
            $override->setSite($this);
        }
        $this->canonicalizationOverride = $override;

        return $this;
    }
}
