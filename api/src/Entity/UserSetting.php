<?php

namespace App\Entity;

use App\Repository\UserSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's global settings (exposed as /users/settings). Null = inherit.
 */
#[ORM\Entity(repositoryClass: UserSettingRepository::class)]
class UserSetting implements SettingsLayerInterface
{
    use RequestContextSettingsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'userSetting')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false)]
    private ?OidcSubjectIdentifier $owner = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $preferredDownloader = null;

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
