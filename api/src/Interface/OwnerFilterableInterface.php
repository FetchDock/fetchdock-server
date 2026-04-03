<?php

namespace App\Interface;

use Doctrine\ORM\QueryBuilder;

interface OwnerFilterableInterface
{
    public static function getOwnerQueryBuilder(QueryBuilder $queryBuilder, string $ownerIdentifier): QueryBuilder;
}
