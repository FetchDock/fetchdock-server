<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Settings shared by all site setting layers. Every field is nullable: null means "inherit from the
 * previous layer" (see App\Service\Settings\EffectiveSettingsResolver).
 *
 * The `site` association is declared by each subclass, because its cardinality differs per layer
 * (one default per site, many user overrides per site).
 */
#[ORM\MappedSuperclass]
abstract class AbstractSiteSetting implements SettingsLayerInterface
{
    use RequestContextSettingsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $downloader = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    abstract public function getSite(): ?Site;

    abstract public function setSite(Site $site): static;

    public function getDownloader(): ?string
    {
        return $this->downloader;
    }

    public function setDownloader(?string $downloader): static
    {
        $this->downloader = $downloader;

        return $this;
    }

    public function getPreferredDownloader(): ?string
    {
        return $this->downloader;
    }
}
