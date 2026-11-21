<?php

namespace App\Tests\Integration\Handler;

use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use App\Handler\DownloadJobHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A failing download job must surface its actual error. The worker gets a copy of the job (the message); recording the
 * failure with that copy used to INSERT the job again, so every failure ended as a duplicate uuid violation.
 */
class DownloadJobHandlerFailureTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ?int $jobId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->jobId) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM download_job_event WHERE download_job_id = ?', [$this->jobId]);
            $connection->executeStatement('DELETE FROM download_job WHERE id = ?', [$this->jobId]);
        }

        parent::tearDown();
    }

    public function testAFailingJobRethrowsItsOwnErrorAndIsMarkedFailed(): void
    {
        $job = new DownloadJob()
            ->setUri('https://example.com/handler-failure-'.bin2hex(random_bytes(4)))
            // An unknown downloader makes the handler fail after picking the job up, without any network access
            ->setDownloader('does-not-exist')
            ->setState(DownloadStateEnum::PENDING)
            ->setOwner($this->entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => 'user']));
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->jobId = $job->getId();

        // Like the transport: the handler gets an unmanaged copy of the job
        $message = unserialize(serialize($job));
        $this->entityManager->clear();

        try {
            static::getContainer()->get(DownloadJobHandler::class)($message);
            $this->fail('The handler should rethrow the failure');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid downloader specified: does-not-exist', $e->getMessage());
        }

        $this->entityManager->clear();
        $stored = $this->entityManager->find(DownloadJob::class, $this->jobId);
        $this->assertSame(DownloadStateEnum::FAILED, $stored->getState());
        $this->assertSame(1, $this->entityManager->getRepository(DownloadJob::class)->count(['uuid' => $job->getUuid()]));
        $events = array_map(
            static fn (DownloadJobEvent $event): ?string => $event->getEvent(),
            $this->entityManager->getRepository(DownloadJobEvent::class)->findBy(['downloadJob' => $this->jobId], ['id' => 'ASC']),
        );
        $this->assertContains('job.failed', $events);
    }
}
