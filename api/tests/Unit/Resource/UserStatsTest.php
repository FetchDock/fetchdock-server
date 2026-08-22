<?php

namespace App\Tests\Unit\Resource;

use App\Entity\OidcSubjectIdentifier;
use App\Repository\UserDownloadStatsRepository;
use App\Resource\UserStats;
use App\Resource\Stats\UserDownloadStats;
use PHPUnit\Framework\TestCase;

class UserStatsTest extends TestCase
{
    public function testGetStatsForUserReturnsUserStats(): void
    {
        $user = new OidcSubjectIdentifier();
        $downloadStats = $this->createMock(UserDownloadStats::class);

        $repository = $this->createMock(UserDownloadStatsRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['owner' => $user])
            ->willReturn($downloadStats);

        $stats = UserStats::getStatsForUser($user, $repository);

        $this->assertInstanceOf(UserStats::class, $stats);
        $this->assertSame($downloadStats, $stats->downloadStats);
    }

    public function testUserStatsHasDownloadStatsProperty(): void
    {
        $stats = new UserStats();
        $this->assertTrue(property_exists($stats, 'downloadStats'));
    }
}
