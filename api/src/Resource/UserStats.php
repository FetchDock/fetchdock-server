<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\OidcSubjectIdentifier;
use App\Repository\UserDownloadStatsRepository;
use App\State\UserStatsProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/user/stats',
            provider: UserStatsProvider::class,
        )
    ]
)]
class UserStats
{
    public Stats\UserDownloadStats $downloadStats;

    public static function getStatsForUser(
        OidcSubjectIdentifier $user,
        UserDownloadStatsRepository $downloadStatsRepository,
    ): self
    {
        $stats = new self();
        $stats->downloadStats = $downloadStatsRepository->findOneBy(['owner' => $user]);

        return $stats;
    }
}
