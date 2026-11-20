<?php

namespace App\Entity;

use App\Repository\ClientPreferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opaque preferences of one kind of client (e.g. "browser-extension": theme, shortcuts), shared by
 * all of the user's clients of that type. Never interpreted by the server.
 *
 * Stored as JSON text, not jsonb: Doctrine decodes jsonb to PHP arrays, which loses the difference
 * between {} and [] in the client's data.
 */
#[ORM\Entity(repositoryClass: ClientPreferenceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_client_preference_owner_client_type', columns: ['owner_id', 'client_type'])]
class ClientPreference
{
    /** Maximum size of a request body, in bytes */
    public const int MAX_BODY_BYTES = 16384;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OidcSubjectIdentifier $owner = null;

    /** See Client::TYPE_PATTERN */
    #[ORM\Column(length: 32)]
    private ?string $clientType = null;

    /** JSON object */
    #[ORM\Column(type: Types::TEXT)]
    private string $data = '{}';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
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

    public function getClientType(): ?string
    {
        return $this->clientType;
    }

    public function setClientType(string $clientType): static
    {
        $this->clientType = $clientType;

        return $this;
    }

    /**
     * The JSON object, as sent by the client.
     */
    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): static
    {
        $this->data = $data;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
