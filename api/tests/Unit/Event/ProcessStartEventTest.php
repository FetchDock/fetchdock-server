<?php

namespace App\Tests\Unit\Event;

use App\Entity\DownloadJob;
use App\Event\ProcessStartEvent;
use PHPUnit\Framework\TestCase;

class ProcessStartEventTest extends TestCase
{
    public function testEventInitialization(): void
    {
        $downloadJob = new DownloadJob();
        $event = new ProcessStartEvent($downloadJob);

        $this->assertSame($downloadJob, $event->downloadJob);
    }

    public function testEventPropertyIsReadonly(): void
    {
        $downloadJob = new DownloadJob();
        $event = new ProcessStartEvent($downloadJob);

        $this->assertTrue(isset($event->downloadJob));
        $this->assertSame($downloadJob, $event->downloadJob);
    }

    public function testIsSymfonyEvent(): void
    {
        $downloadJob = new DownloadJob();
        $event = new ProcessStartEvent($downloadJob);

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}
