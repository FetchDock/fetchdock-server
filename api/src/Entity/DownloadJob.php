<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Enum\DownloadStateEnum;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadJobRepository;
use App\State\DownloadJobQueuedProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DownloadJobRepository::class)]
#[ApiResource(
    operations: [
        new Post(
            status: 202,
            security: "is_granted('ROLE_ALLOW_CREATE_DOWNLOAD_JOB')",
            input: DownloadJobDTO::class,
            output: JobAcceptedDTO::class,
            messenger: 'input',
            processor: DownloadJobQueuedProcessor::class
        ),
        new Get(
            security: "is_granted('ROLE_ALLOW_GET_DOWNLOAD_JOB')"
        ),
        new GetCollection(
            order: ['createdAt' => 'DESC'],
            security: "is_granted('ROLE_ALLOW_LIST_DOWNLOAD_JOBS')"
        ),
    ],
    mercure: true
)]
class DownloadJob implements DownloadJobInterface
{
    use TimestampableEntity;

    #[ApiProperty(identifier: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ApiProperty(identifier: true)]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\Column(length: 64)]
    private ?string $token = null;

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

    /**
     * @var Collection<int, DownloadedFile>
     */
    #[ORM\ManyToMany(targetEntity: DownloadedFile::class, mappedBy: 'downloadJob')]
    private Collection $files;

    #[ORM\ManyToOne(inversedBy: 'downloadJobs')]
    private ?OidcSubjectIdentifier $owner = null;

    public function __construct()
    {
        $this->downloadJobEvents = new ArrayCollection();
        $this->uuid = Uuid::v4();
        $this->token = bin2hex(random_bytes(32)); // 64 character hex string
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getToken(): ?string
    {
        return $this->token;
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

    /**
     * @return Collection<int, DownloadedFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(DownloadedFile $downloadedFile): static
    {
        if (!$this->files->contains($downloadedFile)) {
            $this->files->add($downloadedFile);
            $downloadedFile->addDownloadJob($this);
        }

        return $this;
    }

    public function removeFile(DownloadedFile $downloadedFile): static
    {
        if ($this->files->removeElement($downloadedFile)) {
            $downloadedFile->removeDownloadJob($this);
        }

        return $this;
    }

    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(?OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
