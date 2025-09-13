<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Psr\Http\Message\UriInterface;

final class DownloadJobDTO
{
    #[Assert\Type('string')]
    #[Assert\NotNull]
    public string $uri;

    #[Assert\Type('string')]
    public string $userAgent;

    #[Assert\Type('array')]
    public array $cookies;
}
