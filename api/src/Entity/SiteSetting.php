<?php

namespace App\Entity;

use App\Repository\SiteSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteSettingRepository::class)]
class SiteSetting extends AbstractSiteSetting
{
    #[ORM\OneToOne(inversedBy: 'defaultSiteSetting', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    protected ?SupportedSite $site = null;

    public function getSite(): ?SupportedSite
    {
        return $this->site;
    }

    public function setSite(SupportedSite $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function requiresCookies(): bool
    {
        return $this->sendCookies ?? false;
    }

    public function requiresUserAgent(): bool
    {
        return $this->sendUserAgent ?? false;
    }

    public function requiresReferrer(): bool
    {
        return $this->sendReferrer ?? false;
    }
}
