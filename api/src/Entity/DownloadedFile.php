<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Dto\DownloadedFileOutput;
use App\Repository\DownloadedFileRepository;
use App\State\DownloadedFileCollectionProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\SoftDeleteable\Traits\SoftDeleteableEntity;

#[ApiResource(
    uriTemplate: '/download_jobs/{downloadJobUuid}/files.{_format}',
    operations: [new GetCollection(
        // Only the owner of the job in the URI gets its files, each built for that job (see DownloadedFileOutput)
        output: DownloadedFileOutput::class,
        provider: DownloadedFileCollectionProvider::class,
    )],
    uriVariables: [
        'downloadJobUuid' => new Link(
            toProperty: 'downloadJob',
            fromClass: DownloadJob::class,
            identifiers: ['uuid'],
        ),
    ],
    stateOptions: new Options(
        handleLinks: [self::class, 'handleLinks'],
    )
)]
#[ORM\Entity(repositoryClass: DownloadedFileRepository::class)]
// Soft-deleted once no live (non-deleted) DownloadJob uses the file anymore. The file stays on disk.
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class DownloadedFile
{
    use SoftDeleteableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, DownloadJob>
     */
    #[ORM\ManyToMany(targetEntity: DownloadJob::class, inversedBy: 'files')]
    private Collection $downloadJob;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $path = null;

    #[ORM\Column]
    private array $metadata = [];

    #[ORM\Column]
    private ?bool $visible = null;

    public function __construct()
    {
        $this->downloadJob = new ArrayCollection();
    }

    public static function handleLinks(QueryBuilder $queryBuilder, array $uriVariables, QueryNameGeneratorInterface $queryNameGenerator): void
    {
        $queryBuilder
            ->join($queryBuilder->getRootAliases()[0].'.downloadJob', 'download_job')
            ->andWhere('download_job.uuid = :downloadJob')
            ->andWhere($queryBuilder->getRootAliases()[0].'.visible = true')
            ->setParameter('downloadJob', $uriVariables['downloadJobUuid']);
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

    public function getFilename(): ?string
    {
        return $this->path ? basename($this->path) : null;
    }

    /**
     * @return Collection<int, DownloadJob>
     */
    public function getDownloadJob(): Collection
    {
        return $this->downloadJob;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
