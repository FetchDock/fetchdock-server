<?php

namespace App\Tests\Unit\Event;

use App\Entity\DownloadJob;
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use App\Event\JobPickedUpEvent;
use App\Event\JobUpdateEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

class JobEventsTest extends TestCase
{
    private DownloadJob $downloadJob;

    protected function setUp(): void
    {
        $this->downloadJob = new DownloadJob();
        $this->downloadJob->setUri('https://example.com/test.zip');
    }

    public function testJobPickedUpEvent(): void
    {
        $event = new JobPickedUpEvent($this->downloadJob, 'worker-123');

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame('worker-123', $event->getWorkerIdentifier());
    }

    public function testJobPickedUpEventWithoutWorkerIdentifier(): void
    {
        $event = new JobPickedUpEvent($this->downloadJob);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertNull($event->getWorkerIdentifier());

        $event->setWorkerIdentifier('worker-456');
        $this->assertSame('worker-456', $event->getWorkerIdentifier());
    }

    public function testJobUpdateEvent(): void
    {
        $context = ['downloader' => 'youtube-dl'];
        $event = new JobUpdateEvent($this->downloadJob, 'Downloader selected', $context);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame('Downloader selected', $event->getUpdateMessage());
        $this->assertSame($context, $event->getContext());
    }

    public function testJobUpdateEventWithoutContext(): void
    {
        $event = new JobUpdateEvent($this->downloadJob, 'Progress update');

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame('Progress update', $event->getUpdateMessage());
        $this->assertNull($event->getContext());

        $newContext = ['progress' => 50];
        $event->setContext($newContext);
        $this->assertSame($newContext, $event->getContext());
    }

    public function testJobCompletedEvent(): void
    {
        $metadata = ['file_size' => 1024, 'duration' => '00:02:30'];
        $event = new JobCompletedEvent($this->downloadJob, $metadata);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame($metadata, $event->getMetadata());
    }

    public function testJobCompletedEventWithoutMetadata(): void
    {
        $event = new JobCompletedEvent($this->downloadJob);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertNull($event->getMetadata());

        $metadata = ['status' => 'success'];
        $event->setMetadata($metadata);
        $this->assertSame($metadata, $event->getMetadata());
    }

    public function testJobFailedEvent(): void
    {
        $exception = new \Exception('Download failed');
        $context = ['error_code' => 404];
        $event = new JobFailedEvent($this->downloadJob, $exception, $context);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame($exception, $event->getException());
        $this->assertSame($context, $event->getContext());
    }

    public function testJobFailedEventWithoutContext(): void
    {
        $exception = new \RuntimeException('Network error');
        $event = new JobFailedEvent($this->downloadJob, $exception);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($this->downloadJob, $event->getDownloadJob());
        $this->assertSame($exception, $event->getException());
        $this->assertNull($event->getContext());

        $context = ['retry_count' => 3];
        $event->setContext($context);
        $this->assertSame($context, $event->getContext());
    }
}