<?php

namespace App\Entity;

use App\Repository\ClientSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-client overrides, the last settings layer. Null = inherit.
 */
#[ORM\Entity(repositoryClass: ClientSettingRepository::class)]
class ClientSetting implements SettingsLayerInterface
{
    use RequestContextSettingsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'setting')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $preferredDownloader = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getPreferredDownloader(): ?string
    {
        return $this->preferredDownloader;
    }

    public function setPreferredDownloader(?string $preferredDownloader): static
    {
        $this->preferredDownloader = $preferredDownloader;

        return $this;
    }
}
