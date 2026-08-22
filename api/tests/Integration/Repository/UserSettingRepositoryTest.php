<?php

namespace App\Tests\Integration\Repository;

use App\Repository\UserSettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserSettingRepositoryTest extends KernelTestCase
{
    private UserSettingRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(UserSettingRepository::class);
    }

    public function testRepositoryIsInstantiable(): void
    {
        $this->assertInstanceOf(UserSettingRepository::class, $this->repository);
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
