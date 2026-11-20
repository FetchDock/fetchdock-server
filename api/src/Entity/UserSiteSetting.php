<?php

namespace App\Entity;

use App\Interface\OwnerFilterableInterface;
use App\Repository\UserSiteSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;

/**
 * A user's overrides for one site.
 */
#[ORM\Entity(repositoryClass: UserSiteSettingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_site_setting_owner_site', columns: ['owner_id', 'site_id'])]
class UserSiteSetting extends AbstractSiteSetting implements OwnerFilterableInterface
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Site $site = null;

    #[ORM\ManyToOne(inversedBy: 'userSiteSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OidcSubjectIdentifier $owner = null;

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;

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

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->andWhere(sprintf('%s.owner = :owner', $rootAlias))
            ->setParameter('owner', $ownerIdentifier);
    }
}
