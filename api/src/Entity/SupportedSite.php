<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\SupportedSiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: SupportedSiteRepository::class
)]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    mercure: true,
    order: [
        'name' => 'ASC',
    ]
)]
final class SupportedSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private array $domains = [];

    #[ORM\Column]
    private ?bool $enabled = null;

    #[ORM\Column]
    private array $metadata = [];

    #[ORM\OneToOne(mappedBy: 'site', cascade: ['persist', 'remove'])]
    private ?SiteSetting $defaultSiteSetting = null;

    #[ORM\Column(length: 255)]
    private ?string $downloader = null;

    /**
     * IE:
     * * youtube (for yt-dlp) for youtube links
     * * pixiv (for gallery-dl) for pixiv links
     *
     * @var string|null
     */
    #[ORM\Column(length: 255)]
    private ?string $downloader_specific_identifier = null;

    /**
     * IE:
     * * work for pixiv (for gallery-dl) for pixiv links to artwork pages
     *
     * @var string|null
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloader_specific_sub_identifier = null;

    #[ORM\Column]
    private ?array $downloader_metadata = [];

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDomains(): array
    {
        return $this->domains;
    }

    public function setDomains(array $domains): static
    {
        $this->domains = $domains;

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getDefaultSiteSetting(): ?SiteSetting
    {
        return $this->defaultSiteSetting;
    }

    public function setDefaultSiteSetting(SiteSetting $defaultSiteSetting): static
    {
        // set the owning side of the relation if necessary
        if ($defaultSiteSetting->getSite() !== $this) {
            $defaultSiteSetting->setSite($this);
        }

        $this->defaultSiteSetting = $defaultSiteSetting;

        return $this;
    }

    public function getDownloader(): ?string
    {
        return $this->downloader;
    }

    public function setDownloader(string $downloader): static
    {
        $this->downloader = $downloader;

        return $this;
    }

    public function getDownloaderSpecificIdentifier(): ?string
    {
        return $this->downloader_specific_identifier;
    }

    public function setDownloaderSpecificIdentifier(string $downloader_specific_identifier): static
    {
        $this->downloader_specific_identifier = $downloader_specific_identifier;

        return $this;
    }

    public function getDownloaderSpecificSubIdentifier(): ?string
    {
        return $this->downloader_specific_sub_identifier;
    }

    public function setDownloaderSpecificSubIdentifier(?string $downloader_specific_sub_identifier): static
    {
        $this->downloader_specific_sub_identifier = $downloader_specific_sub_identifier;

        return $this;
    }

    public function getDownloaderMetadata(): ?array
    {
        return $this->downloader_metadata;
    }

    public function setDownloaderMetadata(?array $downloader_metadata): static
    {
        $this->downloader_metadata = $downloader_metadata;

        return $this;
    }
}
