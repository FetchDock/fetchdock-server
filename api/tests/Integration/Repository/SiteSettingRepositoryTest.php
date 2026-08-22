<?php

namespace App\Tests\Integration\Repository;

use App\Entity\SiteSetting;
use App\Repository\SiteSettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SiteSettingRepositoryTest extends KernelTestCase
{
    private SiteSettingRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(SiteSettingRepository::class);
    }

    public function testRepositoryIsInstantiable(): void
    {
        $this->assertInstanceOf(SiteSettingRepository::class, $this->repository);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repository->findAll();
        $this->assertIsArray($result);
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $result = $this->repository->find(99999);
        $this->assertNull($result);
    }

    public function testFindByReturnsArrayForEmptyResult(): void
    {
        $result = $this->repository->findBy(['id' => 99999]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCountReturnsInteger(): void
    {
        $count = $this->repository->count([]);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
