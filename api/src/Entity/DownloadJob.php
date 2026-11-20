<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\CookieDTO;
use App\Dto\DownloadJobDTO;
use App\Dto\JobAcceptedDTO;
use App\Enum\DownloadStateEnum;
use App\Interface\OwnerFilterableInterface;
use App\Mercure\UserTopics;
use App\Model\DownloadJobInterface;
use App\Repository\DownloadJobRepository;
use App\State\DownloadJobDeleteProcessor;
use App\State\DownloadJobQueuedProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
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
        ),
        new GetCollection(
            order: ['createdAt' => 'DESC'],
        ),
        // Soft-deletes the job for its owner; files no longer used by any job are soft-deleted as well
        new Delete(
            security: "is_granted('ROLE_USER') and object.getOwner() !== null and object.getOwner().getSubject() === user.getUserIdentifier()",
            processor: DownloadJobDeleteProcessor::class
        ),
    ],
    // Updates are published privately to the owner's topics only, see mercureOptions()
    mercure: 'object.mercureOptions()'
)]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class DownloadJob implements DownloadJobInterface, OwnerFilterableInterface
{
    use TimestampableEntity;
    use SoftDeleteableEntity;

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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $referrer = null;

    /**
     * @var CookieDTO[]|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $cookies = [];

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

    #[ORM\ManyToOne(cascade: ['persist'], inversedBy: 'downloadJobs')]
    private ?OidcSubjectIdentifier $owner = null;

    public function __construct()
    {
        $this->downloadJobEvents = new ArrayCollection();
        $this->uuid = Uuid::v4();
        $this->token = bin2hex(random_bytes(32));
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

    // Secret for downloading the job's files (see DownloadController): never serialize it.
    // Clients receive it once, in the response to creating the job (JobAcceptedDTO).
    #[Ignore]
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

    // The user's browser user agent: input only; not needed by clients, and it helps fingerprint users.
    #[Ignore]
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    // The page the user sent the job from: input only, it reveals the user's browsing history.
    #[Ignore]
    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): static
    {
        $this->referrer = $referrer;

        return $this;
    }

    /**
     * @return CookieDTO[]|null
     */
    // The user's session cookies for the site: input only, never serialized
    // (API responses and Mercure updates).
    #[Ignore]
    public function getCookies(): ?array
    {
        if (!empty($this->cookies)) {
            // Check if the cookies are instances of CookieDTO
            // if not try to convert them
            foreach ($this->cookies as $key => $cookie) {
                if (!$cookie instanceof CookieDTO) {
                    $this->cookies[$key] = CookieDTO::fromArray($cookie);
                }
            }
        }

        return $this->cookies;
    }

    public function setCookies(?array $cookies): static
    {
        if (!empty($cookies)) {
            foreach ($cookies as $cookie) {
                if (!$cookie instanceof CookieDTO) {
                    throw new \InvalidArgumentException('Cookies must be instances of CookieDTO');
                }

                $this->addCookie($cookie);
            }
        } else {
            $this->cookies = [];
        }

        return $this;
    }

    public function addCookie(CookieDTO $cookie): static
    {
        // Initialize cookies array if it's null
        if (empty($this->cookies) && !is_array($this->cookies)) {
            $this->cookies = [];
        }

        // Do not add cookie if it already exists
        if (!in_array($cookie, $this->cookies ?? [], true)) {
            $this->cookies[] = $cookie;
        }

        return $this;
    }

    public function removeCookie(CookieDTO $cookie): static
    {
        $this->cookies = array_filter($this->cookies, fn ($c) => $c !== $cookie);

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

    // Not needed by clients, and would reveal user ids in Mercure updates.
    #[Ignore]
    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(?OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder->andWhere(sprintf('%s.owner = :owner', $rootAlias))
            ->setParameter('owner', $ownerIdentifier);
    }

    // Cookies in Netscape format for the downloaders: never serialized.
    #[Ignore]
    public function getCookiesNetscapeFileContent(): string
    {
        $lineString = "# Netscape HTTP Cookie File \n# This file was generated by the download API. Do not edit this file manually.\n\n";

        if (!empty($this->cookies)) {
            foreach ($this->cookies as $cookie) {
                $lineString .= $cookie->toNetscapeCookieLine();
            }
        }

        return $lineString;
    }

    /**
     * Mercure options for API Platform's updates of this resource (see the "mercure" option of #[ApiResource]).
     *
     * Updates are always private and published to the owner's topic, so only the owner's authorized clients receive
     * them. Jobs without an owner get no updates, as no topic can be built for them. Not prefixed with "get", so the
     * serializer doesn't expose it.
     *
     * @return array{private: true, topics: list<string>}|false
     */
    public function mercureOptions(): array|false
    {
        $topic = UserTopics::downloadJob($this);
        if (null === $topic) {
            return false;
        }

        return ['private' => true, 'topics' => [$topic]];
    }
}
