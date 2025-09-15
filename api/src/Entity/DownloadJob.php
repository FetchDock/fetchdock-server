<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Enum\DownloadStateEnum;
use App\Model\DownloadJobInterface;
use App\Model\MetubeDownloadJob;
use App\Repository\DownloadJobRepository;
use App\State\DownloadJobQueuedProcessor;
use App\State\MetubeDownloadJobProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

#[ORM\Entity(repositoryClass: DownloadJobRepository::class)]
#[ApiResource(
    operations: [
        new Post(
            status: 202,
            input: DownloadJobDTO::class,
            output: JobAcceptedDTO::class,
            messenger: 'input',
            processor: DownloadJobQueuedProcessor::class,
        ),
        new Post(
            uriTemplate: '/add',
            formats: ['json' => ['application/json']],
            status: 202,
            openapi: false,
            description: "Endpoint for the Metube browser extension to add download jobs.",
            input: MetubeDownloadJob::class,
            output: JobAcceptedDTO::class,
            messenger: 'input',
            processor: MetubeDownloadJobProcessor::class
        )
    ],
    mercure: true
)]
class DownloadJob implements DownloadJobInterface
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloader = null;

    /**
     * @var Collection<int, DownloadJobEvent>
     */
    #[ORM\OneToMany(targetEntity: DownloadJobEvent::class, mappedBy: 'downloadJob', cascade: ['persist'], orphanRemoval: false)]
    private Collection $downloadJobEvents;

    public function __construct()
    {
        $this->downloadJobEvents = new ArrayCollection();
    }

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

    public function getDownloader(): ?string
    {
        return $this->downloader;
    }

    public function setDownloader(string $downloader): static
    {
        $this->downloader = $downloader;

        return $this;
    }

    public function getUrl(): UriInterface
    {
        return new Uri($this->uri);
    }

    /**
     * @return Collection<int, DownloadJobEvent>
     */
    public function getDownloadJobEvents(): Collection
    {
        return $this->downloadJobEvents;
    }

    public function addDownloadJobEvent(DownloadJobEvent $downloadJobEvent): static
    {
        if (!$this->downloadJobEvents->contains($downloadJobEvent)) {
            $this->downloadJobEvents->add($downloadJobEvent);
            $downloadJobEvent->setDownloadJob($this);
        }

        return $this;
    }

    public function removeDownloadJobEvent(DownloadJobEvent $downloadJobEvent): static
    {
        if ($this->downloadJobEvents->removeElement($downloadJobEvent)) {
            // set the owning side to null (unless already changed)
            if ($downloadJobEvent->getDownloadJob() === $this) {
                $downloadJobEvent->setDownloadJob(null);
            }
        }

        return $this;
    }
}
