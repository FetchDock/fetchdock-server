<?php

namespace App\Dto;

use App\Validator as CustomAssert;
use Symfony\Component\Validator\Constraints as Assert;

final class DownloadJobDTO
{
    #[Assert\Type('string')]
    #[Assert\Url(requireTld: true)]
    #[Assert\NotNull]
    public string $uri;

    #[Assert\Type('string')]
    public string $userAgent;

    /**
     * @var CookieDTO[]
     */
    public array $cookies;

    #[Assert\Type('string')]
    #[CustomAssert\SelectDownloader]
    public string $downloader;
}
