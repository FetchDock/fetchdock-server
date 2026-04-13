<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\DownloadJob;
use App\Event\JobCompletedEvent;
use App\EventListener\CleanupListener;
use App\Model\DownloadJobInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupListenerTest extends TestCase
{
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|LoggerInterface $logger;
    private CleanupListener $listener;

    public function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new CleanupListener($this->entityManager, $this->logger);
    }

    public function testOnJobCompletedRemovesCookies(): void
    {
        $downloadJob = $this->createMock(DownloadJob::class);
        $downloadJob->expects($this->once())
            ->method('setCookies')
            ->with(null);

        $jobCompletedEvent = $this->createMock(JobCompletedEvent::class);
        $jobCompletedEvent->expects($this->once())
            ->method('getDownloadJob')
            ->willReturn($downloadJob);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($downloadJob);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->listener->onJobCompleted($jobCompletedEvent);
    }
}
