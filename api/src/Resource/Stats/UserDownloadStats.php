<?php

namespace App\Resource\Stats;

use App\Interface\OwnerFilterableInterface;
use App\Repository\UserDownloadStatsRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\QueryBuilder;

#[ORM\Entity(
    repositoryClass: UserDownloadStatsRepository::class,
    readOnly: true,
)]
class UserDownloadStats implements OwnerFilterableInterface
{
    #[ORM\Id]
    #[ORM\Column(name: 'owner_id', type: 'integer')]
    private int $owner;
    #[ORM\Column(type: 'integer')]
    public int $total;
    #[ORM\Column(type: 'integer')]
    public int $pending;
    #[ORM\Column(type: 'integer')]
    public int $inProgress;
    #[ORM\Column(type: 'integer')]
    public int $completed;
    #[ORM\Column(type: 'integer')]
    public int $failed;
    #[ORM\Column(type: 'integer')]
    public int $canceled;

    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.owner = :ownerId', $rootAlias))
            ->setParameter('ownerId', $ownerIdentifier);

        return $queryBuilder;
    }
}
