<?php

namespace App\Repository;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadJob>
 */
class DownloadJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadJob::class);
    }

    public function findWithoutFiles()
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.files IS EMPTY')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUuidAndToken(string $uuid, string $token): ?DownloadJob
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.uuid = :uuid')
            ->andWhere('d.token = :token')
            ->setParameter('uuid', $uuid)
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUrlAndOwner(string $url, OidcSubjectIdentifier $owner): ?array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.uri = :uri')
            ->andWhere('d.owner = :owner')
            ->setParameter('uri', $url)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult();
    }

    public function findByUrl(string $url): ?array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.uri = :uri')
            ->setParameter('uri', $url)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return DownloadJob[] Returns an array of DownloadJob objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?DownloadJob
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
