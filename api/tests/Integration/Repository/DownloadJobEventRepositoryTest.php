<?php

namespace App\Tests\Integration\Repository;

use App\Repository\DownloadJobEventRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DownloadJobEventRepositoryTest extends KernelTestCase
{
    private DownloadJobEventRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DownloadJobEventRepository::class);
    }

    public function testRepositoryIsInstantiable(): void
    {
        $this->assertInstanceOf(DownloadJobEventRepository::class, $this->repository);
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
