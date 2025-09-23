<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Repository\DownloadedFileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    uriTemplate: '/download_jobs/{downloadJobUuid}/files.{_format}',
    operations: [new GetCollection(
        normalizationContext: ['groups' => ['downloadedFile:read']]
    )],
    uriVariables: [
        'downloadJobUuid' => new Link(
            toProperty: 'downloadJob',
            fromClass: DownloadJob::class,
            identifiers: ['uuid'],
        )
    ],
    stateOptions: new Options(
        handleLinks: [self::class, 'handleLinks'],
    )
)]
#[ORM\Entity(repositoryClass: DownloadedFileRepository::class)]
class DownloadedFile
{
    #[Groups(['downloadedFile:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, DownloadJob>
     */
    #[Groups(['downloadedFile:read'])]
    #[ORM\ManyToMany(targetEntity: DownloadJob::class, inversedBy: 'files')]
    private Collection $downloadJob;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $path = null;

    #[ORM\Column]
    private array $metadata = [];

    #[Groups(['downloadedFile:read'])]
    #[ORM\Column]
    private ?bool $visible = null;

    public function __construct()
    {
        $this->downloadJob = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, DownloadJob>
     */
    public function getDownloadJob(): Collection
    {
        return $this->downloadJob;
    }

    public function addDownloadJob(DownloadJob $downloadJob): static
    {
        if (!$this->downloadJob->contains($downloadJob)) {
            $this->downloadJob->add($downloadJob);
        }

        return $this;
    }

    public function removeDownloadJob(DownloadJob $downloadJob): static
    {
        $this->downloadJob->removeElement($downloadJob);

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function isVisible(): ?bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    #[Groups(['downloadedFile:read'])]
    public function getFilename(): ?string
    {
        return $this->path ? basename($this->path) : null;
    }

    #[Groups(['downloadedFile:read'])]
    public function getDownloadUri(): ?string
    {
        // /downloads/{downloadJobId}/files/{downloadedFileId}/{token}/download
        return '/downloads/'.$this->getDownloadJob()->first()->getUuid().'/'. $this->getDownloadJob()->first()->getToken() .'/files/'.$this->getId();
    }

    public static function handleLinks(QueryBuilder $queryBuilder, array $uriVariables, QueryNameGeneratorInterface $queryNameGenerator): void
    {
        $queryBuilder
            ->join($queryBuilder->getRootAliases()[0].'.downloadJob', 'download_job')
            ->andWhere('download_job.uuid = :downloadJob')
            ->andWhere($queryBuilder->getRootAliases()[0].'.visible = true')
            ->setParameter('downloadJob', $uriVariables['downloadJobUuid']);
    }
}
