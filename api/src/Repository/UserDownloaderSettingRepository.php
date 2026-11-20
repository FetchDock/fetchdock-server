<?php

namespace App\Repository;

use App\Entity\UserDownloaderSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserDownloaderSetting>
 */
class UserDownloaderSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDownloaderSetting::class);
    }
}
