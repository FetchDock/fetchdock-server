<?php

namespace App\Tests\Unit\Event;

use App\Entity\DownloadJob;
use App\Event\CliProcessStartEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CliProcessStartEventTest extends TestCase
{
    public function testEventInitialization(): void
    {
        $command = 'echo "test"';
        $downloadJob = new DownloadJob();
        $process = new Process(['echo', 'test']);

        $event = new CliProcessStartEvent($command, $downloadJob, $process);

        $this->assertSame($command, $event->command);
        $this->assertSame($downloadJob, $event->downloadJob);
        $this->assertSame($process, $event->process);
    }

    public function testEventPropertiesAreReadonly(): void
    {
        $command = 'ls -la';
        $downloadJob = new DownloadJob();
        $process = new Process(['ls', '-la']);

        $event = new CliProcessStartEvent($command, $downloadJob, $process);

        $this->assertTrue(isset($event->command));
        $this->assertTrue(isset($event->process));
    }
}
