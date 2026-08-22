<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\OidcSubjectIdentifierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;


#[ORM\Entity(repositoryClass: OidcSubjectIdentifierRepository::class)]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: self::URI_TEMPLATE,
        ),
        new Post(
            uriTemplate: self::URI_TEMPLATE,
            input: __CLASS__
        ),
        new Patch(
            uriTemplate: self::URI_TEMPLATE,
            input: __CLASS__
        )
    ],
    normalizationContext: ['groups' => ['settings:view']],
    denormalizationContext: ['groups' => ['settings:edit']],
)]
class UserSetting
{
    public CONST URI_TEMPLATE = '/users/settings.{_format}';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Groups(['settings:view', 'settings:edit'])]
    public bool $syncBrowsers;

    #[ORM\OneToOne(inversedBy: 'userSetting', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false)]
    private ?OidcSubjectIdentifier $owner = null;

    public function getOwner(): ?OidcSubjectIdentifier
    {
        return $this->owner;
    }

    public function setOwner(OidcSubjectIdentifier $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
