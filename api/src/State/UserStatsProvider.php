<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Doctrine\CurrentUserExtension;
use App\Repository\UserDownloadStatsRepository;
use App\Resource\Stats\UserDownloadStats;

class UserStatsProvider implements ProviderInterface
{

    public function __construct(
        private readonly UserDownloadStatsRepository $downloadStatsRepository,
        private readonly CurrentUserExtension $currentUserExtension
    )
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $queryBuilder = $this->downloadStatsRepository->createQueryBuilder('ds');
        $this->currentUserExtension->addWhere($queryBuilder, UserDownloadStats::class);
        if ($operation instanceof CollectionOperationInterface) {
            return $queryBuilder
                ->getQuery()->getResult();
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }
}
