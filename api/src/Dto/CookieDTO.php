<?php

namespace App\Dto;

use Uri\Rfc3986\Uri;

final class CookieDTO
{
    public string $domain;
    public ?\DateTimeInterface $expirationDate;
    public bool $hostOnly;
    public bool $httpOnly;
    public string $name;
    public string $path;
    public string $sameSite;
    public bool $secure = true;
    public string $value;
    public bool $session;

    /**
     * Return the cookie as a string representation in the Netscape cookie file format.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc6265
     * @see https://curl.se/docs/http-cookies.html
     *
     * @return string
     */
    public function toNetscapeCookieLine(): string
    {
        return sprintf(
            "%s\t%s\t%s\t%s\t%s\t%s\t%s",
            $this->domain,
            (!empty($this->hostOnly)) ? 'FALSE' : 'TRUE',
            $this->path,
            $this->secure ? 'TRUE' : 'FALSE',
            $this->expirationDate ? $this->expirationDate->getTimestamp() : '0',
            $this->name,
            $this->value,
        );
    }
}
