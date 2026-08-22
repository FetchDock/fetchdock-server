<?php

namespace App\Tests\Integration\Repository;

use App\Repository\UserDownloadStatsRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserDownloadStatsRepositoryTest extends KernelTestCase
{
    private UserDownloadStatsRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(UserDownloadStatsRepository::class);
    }

    public function testRepositoryIsInstantiable(): void
    {
        $this->assertInstanceOf(UserDownloadStatsRepository::class, $this->repository);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repository->findAll();
        $this->assertIsArray($result);
    }

    public function testCountReturnsInteger(): void
    {
        $count = $this->repository->count([]);
        $this->assertIsInt($count);
    }
}
