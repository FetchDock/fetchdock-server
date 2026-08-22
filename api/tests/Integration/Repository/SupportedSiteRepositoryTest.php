<?php

namespace App\Tests\Integration\Repository;

use App\Repository\SupportedSiteRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SupportedSiteRepositoryTest extends KernelTestCase
{
    private SupportedSiteRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(SupportedSiteRepository::class);
    }

    public function testRepositoryIsInstantiable(): void
    {
        $this->assertInstanceOf(SupportedSiteRepository::class, $this->repository);
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
