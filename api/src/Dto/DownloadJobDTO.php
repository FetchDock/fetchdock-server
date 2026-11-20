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
     * The page the URL was found on. Input only, never serialized.
     */
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 2048)]
    public ?string $referrer = null;

    /**
     * @var CookieDTO[]
     */
    public array $cookies;

    #[Assert\Type('string')]
    #[CustomAssert\SelectDownloader]
    public string $downloader;

    /**
     * @var bool Allow DownloadJob to be accepted eventhough one already exists with the same uri
     */
    public bool $force = false;
}
