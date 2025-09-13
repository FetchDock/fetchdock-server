<?php

namespace App\Model;

use Psr\Http\Message\UriInterface;

interface DownloadJobInterface
{
    public function getUri(): ?string;
    public function getUrl(): UriInterface;
    public function getUserAgent(): ?string;
    public function getCookies(): ?array;
}
