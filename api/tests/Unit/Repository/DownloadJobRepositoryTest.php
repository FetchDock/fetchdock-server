<?php

namespace App\Tests\Unit\Repository;

use App\Entity\DownloadJob;
use App\Repository\DownloadJobRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class DownloadJobRepositoryTest extends TestCase
{
    private DownloadJobRepository $repository;
    private ManagerRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        
        // Mock the parent constructor requirements
        $this->registry->method('getManagerForClass')
            ->with(DownloadJob::class)
            ->willReturn($this->createMock(\Doctrine\ORM\EntityManagerInterface::class));
    }

    public function testConstructor(): void
    {
        $repository = new DownloadJobRepository($this->registry);
        
        $this->assertInstanceOf(DownloadJobRepository::class, $repository);
    }

    public function testInheritsFromServiceEntityRepository(): void
    {
        $repository = new DownloadJobRepository($this->registry);
        
        $this->assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class, $repository);
    }

    public function testRepositoryEntityClass(): void
    {
        $repository = new DownloadJobRepository($this->registry);
        
        // Test that the repository is configured for the correct entity
        $reflection = new \ReflectionClass($repository);
        $parentReflection = $reflection->getParentClass();
        
        // This verifies the repository extends ServiceEntityRepository
        $this->assertTrue($parentReflection->getName() === \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class);
    }

    /**
     * Test that the repository can be instantiated without errors
     * This is a basic integration test to ensure proper setup
     */
    public function testRepositoryInstantiation(): void
    {
        $this->expectNotToPerformAssertions();
        
        try {
            new DownloadJobRepository($this->registry);
        } catch (\Throwable $e) {
            $this->fail('Repository should instantiate without errors: ' . $e->getMessage());
        }
    }
}