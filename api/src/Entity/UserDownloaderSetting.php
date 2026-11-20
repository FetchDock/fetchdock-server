<?php

namespace App\Entity;

use App\Repository\UserDownloaderSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's settings for one downloader. Null = inherit (enabled, factory order).
 */
#[ORM\Entity(repositoryClass: UserDownloaderSettingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_downloader_setting_owner_downloader', columns: ['owner_id', 'downloader'])]
class UserDownloaderSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OidcSubjectIdentifier $owner = null;

    #[ORM\Column(length: 64)]
    private ?string $downloader = null;

    #[ORM\Column(nullable: true)]
    private ?bool $enabled = null;

    /**
     * Lower values are tried first. Null keeps the server's default order.
     */
    #[ORM\Column(nullable: true)]
    private ?int $priority = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

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

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(?bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(?int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }
}
