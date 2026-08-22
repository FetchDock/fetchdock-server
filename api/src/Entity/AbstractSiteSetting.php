<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
class AbstractSiteSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\JoinColumn(nullable: false)]
    protected ?SupportedSite $site = null;

    #[ORM\Column]
    protected ?bool $sendCookies = null;

    #[ORM\Column]
    protected ?bool $sendUserAgent = null;

    #[ORM\Column]
    protected ?bool $sendReferrer = null;

    #[ORM\Column(length: 255)]
    protected ?string $downloader = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?SupportedSite
    {
        return $this->site;
    }

    public function setSite(SupportedSite $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function isSendCookies(): ?bool
    {
        return $this->sendCookies;
    }

    public function setSendCookies(bool $sendCookies): static
    {
        $this->sendCookies = $sendCookies;

        return $this;
    }

    public function isSendUserAgent(): ?bool
    {
        return $this->sendUserAgent;
    }

    public function setSendUserAgent(bool $sendUserAgent): static
    {
        $this->sendUserAgent = $sendUserAgent;

        return $this;
    }

    public function isSendReferrer(): ?bool
    {
        return $this->sendReferrer;
    }

    public function setSendReferrer(bool $sendReferrer): static
    {
        $this->sendReferrer = $sendReferrer;

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
}
