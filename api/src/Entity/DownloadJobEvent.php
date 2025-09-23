<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Repository\DownloadJobEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: DownloadJobEventRepository::class)]
#[ApiResource(
    uriTemplate: '/download_jobs/{downloadJobUuid}/events.{_format}',
    operations: [new GetCollection()],
    uriVariables: [
        'downloadJobUuid' => new Link(
            #fromProperty: 'downloadJobEvents',
            toProperty: 'downloadJob',
            fromClass: DownloadJob::class,
            identifiers: ['uuid'],
        )
    ],
    stateOptions: new Options(
        handleLinks: [self::class, 'handleLinks'],
    )
)]
class DownloadJobEvent
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'downloadJobEvents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DownloadJob $downloadJob = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workerIdentifier = null;

    #[ORM\Column(length: 255)]
    private ?string $event = null;

    #[ORM\Column(length: 255)]
    private ?string $source = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $updateMessage = null;

    #[ORM\Column(nullable: true)]
    private ?array $context = null;

    #[ORM\Column(nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionMessage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDownloadJob(): ?DownloadJob
    {
        return $this->downloadJob;
    }

    public function setDownloadJob(?DownloadJob $downloadJob): static
    {
        $this->downloadJob = $downloadJob;

        return $this;
    }

    public function getWorkerIdentifier(): ?string
    {
        return $this->workerIdentifier;
    }

    public function setWorkerIdentifier(string $workerIdentifier): static
    {
        $this->workerIdentifier = $workerIdentifier;

        return $this;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function setEvent(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getUpdateMessage(): ?string
    {
        return $this->updateMessage;
    }

    public function setUpdateMessage(?string $updateMessage): static
    {
        $this->updateMessage = $updateMessage;

        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    public function setExceptionMessage(string $exceptionMessage): static
    {
        $this->exceptionMessage = $exceptionMessage;

        return $this;
    }

    public static function handleLinks(QueryBuilder $queryBuilder, array $uriVariables, QueryNameGeneratorInterface $queryNameGenerator): void
    {
        $queryBuilder
            ->join($queryBuilder->getRootAliases()[0].'.downloadJob', 'download_job')
            ->andWhere('download_job.uuid = :downloadJob')
            ->setParameter('downloadJob', $uriVariables['downloadJobUuid']);
    }
}
