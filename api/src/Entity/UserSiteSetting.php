<?php

namespace App\Entity;

use App\Interface\OwnerFilterableInterface;
use App\Repository\UserSiteSettingRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Command line options by downloader identifier, overriding the user's downloader options for
     * this site (see GET /downloaders/{id}/options-schema). Null = none.
     *
     * @var array<string, array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $downloaderOptions = null;

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

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function getDownloaderOptions(): ?array
    {
        return $this->downloaderOptions;
    }

    /**
     * @param array<string, array<string, mixed>>|null $downloaderOptions
     */
    public function setDownloaderOptions(?array $downloaderOptions): static
    {
        $this->downloaderOptions = [] === $downloaderOptions ? null : $downloaderOptions;

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
