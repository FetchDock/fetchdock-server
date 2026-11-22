<?php

namespace App\Tests\Integration\Integrity;

use App\Entity\InstanceIntegrityCheck;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Repository\InstanceIntegrityCheckRepository;
use App\Service\Integrity\IntegrityStatusResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IntegrityStatusResolverTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private IntegrityStatusResolver $resolver;
    /** Far ahead, so checks recorded by other tests fall outside the window */
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        // Not used by a service yet (the privacy policy endpoints will), so the container inlined it away
        $this->resolver = new IntegrityStatusResolver(self::getContainer()->get(InstanceIntegrityCheckRepository::class));
        $this->now = new \DateTimeImmutable('2100-01-10 12:00:00');
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testUnknownWithoutRecentChecks(): void
    {
        $this->record(IntegrityStatus::VERIFIED, '-49 hours');

        self::assertSame(IntegrityStatus::UNKNOWN, $this->resolver->effectiveStatus($this->now));
    }

    public function testVerifiedWhenEveryRecentCheckIsVerified(): void
    {
        $this->record(IntegrityStatus::VERIFIED, '-1 hour');
        $this->record(IntegrityStatus::VERIFIED, '-47 hours', IntegrityRole::WORKER);
        $this->record(IntegrityStatus::MODIFIED, '-49 hours');

        self::assertSame(IntegrityStatus::VERIFIED, $this->resolver->effectiveStatus($this->now));
    }

    public function testWorstRecentCheckWins(): void
    {
        $this->record(IntegrityStatus::VERIFIED, '-1 hour');
        $this->record(IntegrityStatus::UNKNOWN, '-30 hours', IntegrityRole::WORKER);
        self::assertSame(IntegrityStatus::UNKNOWN, $this->resolver->effectiveStatus($this->now));

        $this->record(IntegrityStatus::MODIFIED, '-40 hours', IntegrityRole::WORKER);
        self::assertSame(IntegrityStatus::MODIFIED, $this->resolver->effectiveStatus($this->now));
    }

    private function record(IntegrityStatus $status, string $age, IntegrityRole $role = IntegrityRole::API): void
    {
        $this->entityManager->persist(new InstanceIntegrityCheck($role->value.'-1', $role, $status, null, '0.9.0', [], $this->now->modify($age)));
        $this->entityManager->flush();
    }
}
