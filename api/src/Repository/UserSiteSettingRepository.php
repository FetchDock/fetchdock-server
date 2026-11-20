<?php

namespace App\Repository;

use App\Entity\OidcSubjectIdentifier;
use App\Entity\UserSiteSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSiteSetting>
 */
class UserSiteSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSiteSetting::class);
    }

    /**
     * @return list<UserSiteSetting> the owner's overrides with their sites, by site name
     */
    public function findByOwner(OidcSubjectIdentifier $owner): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('site')
            ->innerJoin('s.site', 'site')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('site.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
