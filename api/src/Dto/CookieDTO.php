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

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->domain = $data['domain'];

        if (is_int($data['expirationDate'])) {
            $dto->expirationDate = (new \DateTimeImmutable())->setTimestamp($data['expirationDate']);
        } elseif (is_array($data['expirationDate']) && array_key_exists("timezone_type", $data['expirationDate'])) {
            // $data is most likely a serialized DateTime object
            // Which contains a "date", "timezone" and "timezone_type" key
            // Where "timezone" is the timezone name ("z") and "timezone_type" is the type of timezone (3 = PHP_INT_MAX)
            $dto->expirationDate = new \DateTimeImmutable($data['expirationDate']['date'], new \DateTimeZone($data['expirationDate']['timezone']));
        } else {
            $dto->expirationDate = null;
        }

        if(isset($data['hostOnly'])) {
            $dto->hostOnly = $data['hostOnly'];
        }
        $dto->httpOnly = $data['httpOnly'];
        $dto->name = $data['name'];
        $dto->path = $data['path'];
        $dto->sameSite = $data['sameSite'];
        $dto->secure = $data['secure'];
        if(isset($data['value'])) {
            $dto->value = $data['value'];
        }

        return $dto;
    }

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
            "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
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
