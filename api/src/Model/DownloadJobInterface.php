<?php

namespace App\Model;

use App\Dto\CookieDTO;
use Psr\Http\Message\UriInterface;

interface DownloadJobInterface
{
    public function getUri(): ?string;

    public function getUrl(): UriInterface;

    public function getUserAgent(): ?string;

    /**
     * @return CookieDTO[]|null
     */
    public function getCookies(): ?array;
}
