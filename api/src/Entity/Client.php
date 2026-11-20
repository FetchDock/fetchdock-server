<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Interface\OwnerFilterableInterface;
use App\Repository\ClientRepository;
use App\State\ClientRegistrationProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A registered client (browser extension, app, ...) of a user. Clients send their id in the
 * X-Client-Id header, which enables their per-client settings layer (/users/clients/{clientId}/settings).
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Index(name: 'idx_client_owner', columns: ['owner_id'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/users/clients'),
        new Post(
            uriTemplate: '/users/clients',
            description: 'Registers a client. Send the returned id in the X-Client-Id header of later requests.',
            denormalizationContext: ['groups' => ['client:write', 'client:create']],
            processor: ClientRegistrationProcessor::class,
        ),
        new Get(uriTemplate: '/users/clients/{id}'),
        new Patch(uriTemplate: '/users/clients/{id}', description: 'Renames a client.'),
        new Delete(uriTemplate: '/users/clients/{id}', description: 'Revokes a client and removes its settings.'),
    ],
    normalizationContext: ['groups' => ['client:read']],
    denormalizationContext: ['groups' => ['client:write']],
    security: "is_granted('ROLE_USER')",
    order: ['createdAt' => 'ASC'],
)]
class Client implements OwnerFilterableInterface
{
    public const string TYPE_PATTERN = '/^[a-z0-9-]{1,32}$/';

    #[ApiProperty(identifier: true)]
    #[Groups(['client:read'])]
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OidcSubjectIdentifier $owner = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['client:read', 'client:write'])]
    #[ORM\Column(length: 100)]
    private ?string $name = null;

    /**
     * Kind of client, e.g. "browser-extension". Set on registration only.
     */
    #[Assert\NotBlank]
    #[Assert\Regex(self::TYPE_PATTERN)]
    #[Groups(['client:read', 'client:create'])]
    #[ORM\Column(length: 32)]
    private ?string $type = null;

    #[Groups(['client:read'])]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[Groups(['client:read'])]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\OneToOne(mappedBy: 'client', cascade: ['persist', 'remove'])]
    private ?ClientSetting $setting = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getSetting(): ?ClientSetting
    {
        return $this->setting;
    }

    public function setSetting(?ClientSetting $setting): static
    {
        $setting?->setClient($this);
        $this->setting = $setting;

        return $this;
    }

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->andWhere(sprintf('%s.owner = :owner', $rootAlias))
            ->setParameter('owner', $ownerIdentifier);
    }
}
