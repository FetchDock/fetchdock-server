<?php

namespace App\Entity;

use App\Repository\SiteExtractorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Maps a downloader's extractor (gallery-dl category, yt-dlp extractor key) to a site.
 */
#[ORM\Entity(repositoryClass: SiteExtractorRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_site_extractor_downloader_key', columns: ['downloader', 'extractor_key'])]
class SiteExtractor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'extractors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Site $site = null;

    /**
     * Downloader identifier, e.g. "gallery-dl-cli" (see App\Factory\DownloaderFactory).
     */
    #[Groups(['site:item'])]
    #[ORM\Column(length: 64)]
    private ?string $downloader = null;

    #[Groups(['site:item'])]
    #[SerializedName('key')]
    #[ORM\Column(length: 128)]
    private ?string $extractorKey = null;

    public function __construct(?string $downloader = null, ?string $extractorKey = null)
    {
        $this->downloader = $downloader;
        $this->extractorKey = $extractorKey;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;

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

    public function getExtractorKey(): ?string
    {
        return $this->extractorKey;
    }

    public function setExtractorKey(string $extractorKey): static
    {
        $this->extractorKey = $extractorKey;

        return $this;
    }
}
