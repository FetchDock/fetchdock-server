<?php

namespace App\Repository;

use App\Entity\OidcSubjectIdentifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<OidcSubjectIdentifier>
 */
class OidcSubjectIdentifierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OidcSubjectIdentifier::class);
    }

    /**
     * UUIDs of all users, without hydrating them (e.g. to build each user's Mercure topic).
     *
     * @return iterable<Uuid>
     */
    public function iterateUuids(): iterable
    {
        foreach ($this->createQueryBuilder('o')->select('o.uuid')->orderBy('o.id')->getQuery()->toIterable() as $row) {
            yield $row['uuid'];
        }
    }

    //    /**
    //     * @return OidcSubjectIdentifier[] Returns an array of OidcSubjectIdentifier objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?OidcSubjectIdentifier
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
