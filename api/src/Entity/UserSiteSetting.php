<?php

namespace App\Entity;

use App\Interface\OwnerFilterableInterface;
use App\Repository\SiteSettingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;

#[ORM\Entity(repositoryClass: SiteSettingRepository::class)]
class UserSiteSetting extends AbstractSiteSetting implements OwnerFilterableInterface
{
    #[ORM\OneToOne(inversedBy: 'defaultSiteSetting', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    protected ?SupportedSite $site = null;

    /**
     * @var Collection<int, OidcSubjectIdentifier>
     */
    #[ORM\ManyToMany(targetEntity: OidcSubjectIdentifier::class, inversedBy: 'userSiteSettings')]
    private Collection $owner;

    public function __construct()
    {
        $this->owner = new ArrayCollection();
    }

    public function getSite(): ?SupportedSite
    {
        return $this->site;
    }

    public function setSite(SupportedSite $site): static
    {
        $this->site = $site;

        return $this;
    }

    /**
     * @return Collection<int, OidcSubjectIdentifier>
     */
    public function getOwner(): Collection
    {
        return $this->owner;
    }

    public function addOwner(OidcSubjectIdentifier $owner): static
    {
        if (!$this->owner->contains($owner)) {
            $this->owner->add($owner);
        }

        return $this;
    }

    public function removeOwner(OidcSubjectIdentifier $owner): static
    {
        $this->owner->removeElement($owner);

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
