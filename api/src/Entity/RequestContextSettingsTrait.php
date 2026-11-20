<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Which parts of the request context (cookies, user agent, referrer) are sent with a download.
 * Null means "inherit from the previous settings layer".
 */
trait RequestContextSettingsTrait
{
    #[ORM\Column(nullable: true)]
    protected ?bool $sendCookies = null;

    #[ORM\Column(nullable: true)]
    protected ?bool $sendUserAgent = null;

    #[ORM\Column(nullable: true)]
    protected ?bool $sendReferrer = null;

    public function isSendCookies(): ?bool
    {
        return $this->sendCookies;
    }

    public function setSendCookies(?bool $sendCookies): static
    {
        $this->sendCookies = $sendCookies;

        return $this;
    }

    public function isSendUserAgent(): ?bool
    {
        return $this->sendUserAgent;
    }

    public function setSendUserAgent(?bool $sendUserAgent): static
    {
        $this->sendUserAgent = $sendUserAgent;

        return $this;
    }

    public function isSendReferrer(): ?bool
    {
        return $this->sendReferrer;
    }

    public function setSendReferrer(?bool $sendReferrer): static
    {
        $this->sendReferrer = $sendReferrer;

        return $this;
    }
}
