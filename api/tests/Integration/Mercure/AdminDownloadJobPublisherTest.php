<?php

namespace App\Tests\Integration\Mercure;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Doctrine\SoftDeleteFilter;
use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use App\EventListener\AdminDownloadJobPublisher;
use App\Mercure\AdminTopics;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Live updates of download jobs for the admin views (#55), see AdminDownloadJobPublisher.
 */
class AdminDownloadJobPublisherTest extends KernelTestCase
{
    private const array EVENTS = [Events::onFlush, SoftDeleteableListener::POST_SOFT_DELETE, Events::postFlush];

    /** @var list<Update> */
    private array $published = [];
    private AdminDownloadJobPublisher $publisher;
    private AdminTopics $topics;
    private EntityManagerInterface $entityManager;
    private ?int $jobId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $hub = new MockHub('https://hub.example.test/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });
        $this->topics = new AdminTopics($hub);
        $this->publisher = new AdminDownloadJobPublisher(
            $hub,
            $this->topics,
            $container->get(SerializerInterface::class),
            $container->get(ResourceMetadataCollectionFactoryInterface::class),
            $container->get(SoftDeleteFilter::class),
            new NullLogger(),
        );
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getEventManager()->addEventListener(self::EVENTS, $this->publisher);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getEventManager()->removeEventListener(self::EVENTS, $this->publisher);
        if (null !== $this->jobId) {
            // Soft-deleteable without hard deletes: remove the row directly
            $this->entityManager->getConnection()->executeStatement('DELETE FROM download_job WHERE id = ?', [$this->jobId]);
        }

        parent::tearDown();
    }

    public function testPublishesNewAndSoftDeletedJobsPrivatelyOnTheirAdminTopic(): void
    {
        $owner = $this->entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => 'user']);
        $job = new DownloadJob()
            ->setUri('https://example.com/admin-publisher-'.bin2hex(random_bytes(4)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::PENDING)
            ->setOwner($owner);
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->jobId = $job->getId();
        $uuid = $job->getUuid()->toRfc4122();

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertTrue($update->isPrivate());
        $this->assertSame(['https://hub.example.test/admin/download_jobs/'.$uuid], $update->getTopics());
        $data = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        // The admin resource's JSON-LD item output: the admin replaces its cached record with it
        $this->assertSame('/admin/download_jobs/'.$uuid, $data['@id']);
        $this->assertSame('AdminDownloadJob', $data['@type']);
        $this->assertSame('user', $data['owner']['subject']);
        $this->assertNull($data['deletedAt']);
        $this->assertArrayNotHasKey('token', $data);

        // The owner deletes the job (soft delete)
        $this->published = [];
        $this->entityManager->remove($job);
        $this->entityManager->flush();

        $this->assertCount(1, $this->published);
        $this->assertNotNull(json_decode($this->published[0]->getData(), true, flags: JSON_THROW_ON_ERROR)['deletedAt']);
    }

    public function testFlushesWithoutJobsPublishNothing(): void
    {
        $this->entityManager->flush();

        $this->assertSame([], $this->published);
    }

    public function testAdminTopicsUseTheHubOrigin(): void
    {
        $this->assertSame('https://hub.example.test', $this->topics->base());
        $this->assertSame('https://hub.example.test/admin/{+path}', $this->topics->subscribeSelector());
    }
}
