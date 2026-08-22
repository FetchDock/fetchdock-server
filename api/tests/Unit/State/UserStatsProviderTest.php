<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Doctrine\CurrentUserExtension;
use App\Repository\UserDownloadStatsRepository;
use App\Resource\Stats\UserDownloadStats;
use App\State\UserStatsProvider;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class UserStatsProviderTest extends TestCase
{
    private UserDownloadStatsRepository $downloadStatsRepository;
    private CurrentUserExtension $currentUserExtension;
    private QueryBuilder $queryBuilder;
    private Query $query;

    private UserStatsProvider $userStatsProvider;

    protected function setUp(): void
    {
        $this->downloadStatsRepository = $this->createMock(UserDownloadStatsRepository::class);

        $this->currentUserExtension = $this->createMock(CurrentUserExtension::class);

        $this->userStatsProvider = new UserStatsProvider(
            $this->downloadStatsRepository,
            $this->currentUserExtension
        );

        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(Query::class);
    }

    public function testWithGetOperation()
    {
        $this->setAssertions();

        $this->query->expects($this->once())
            ->method('getOneOrNullResult');

        $this->userStatsProvider->provide(new Get());
    }

    public function testWithGetCollectionOperation()
    {
        $this->setAssertions();

        $this->query->expects($this->once())
            ->method('getResult');

        $this->userStatsProvider->provide(new GetCollection());
    }

    private function setAssertions()
    {
        $this->downloadStatsRepository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('ds')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);

        $this->currentUserExtension->expects($this->once())
            ->method('addWhere')
            ->with($this->queryBuilder, UserDownloadStats::class);
    }
}
