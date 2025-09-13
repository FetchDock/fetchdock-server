<?php

namespace App\Entity;

use ApiPlatform\Metadata\Post;
use App\Dto\DownloadJobDTO;
use App\Enum\DownloadStateEnum;
use App\Repository\DownloadJobRepository;
use App\State\DownloadJobQueuedProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: DownloadJobRepository::class)]
#[Post(
    status: 202,
    input: DownloadJobDTO::class,
    output: false,
    messenger: 'input',
    processor: DownloadJobQueuedProcessor::class
)]
class DownloadJob
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $uri = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(nullable: true)]
    private ?array $cookies = null;

    #[ORM\Column(enumType: DownloadStateEnum::class)]
    private ?DownloadStateEnum $state = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function setUri(string $uri): static
    {
        $this->uri = $uri;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getCookies(): ?array
    {
        return $this->cookies;
    }

    public function setCookies(?array $cookies): static
    {
        $this->cookies = $cookies;

        return $this;
    }

    public function getState(): ?DownloadStateEnum
    {
        return $this->state;
    }

    public function setState(DownloadStateEnum $state): static
    {
        $this->state = $state;

        return $this;
    }
}
