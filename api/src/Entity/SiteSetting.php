<?php

namespace App\Entity;

use App\Repository\SiteSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-site defaults, managed by admins (config/sites.yaml, see app:sites:sync).
 */
#[ORM\Entity(repositoryClass: SiteSettingRepository::class)]
class SiteSetting extends AbstractSiteSetting
{
    #[ORM\OneToOne(inversedBy: 'defaultSetting')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Site $site = null;

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;

        return $this;
    }
}
