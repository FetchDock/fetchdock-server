<?php

namespace App\Model;

use App\Dto\CookieDTO;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\Common\Collections\Collection;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Uid\Uuid;

interface DownloadJobInterface
{
    public function getId(): ?int;
    public function getUuid(): ?Uuid;
    public function getToken(): ?string;
    public function getUri(): ?string;

    public function getUrl(): UriInterface;

    public function getUserAgent(): ?string;

    /**
     * @return CookieDTO[]|null
     */
    public function getCookies(): ?array;
    public function getState(): ?DownloadStateEnum;
    public function setState(DownloadStateEnum $state): static;
    public function getDownloader(): ?string;
    public function getDownloadJobEvents(): Collection;
    public function getFiles(): Collection;
    public function getOwner(): ?OidcSubjectIdentifier;

    public function getCookiesNetscapeFileContent(): string;
}
