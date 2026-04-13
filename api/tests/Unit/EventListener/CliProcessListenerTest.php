<?php

namespace App\Tests\Unit\EventListener;

use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStdOutputEvent;
use App\EventListener\CliProcessListener;
use App\Model\DownloadJobInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Process\Process;

class CliProcessListenerTest extends TestCase
{
    private MockObject|HubInterface $hub;
    private MockObject|LoggerInterface $logger;
    private MockObject|DownloadJobInterface $downloadJob;
    private MockObject|Process $process;

    private CliProcessListener $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->downloadJob = $this->createMock(DownloadJobInterface::class);
        $this->process = $this->createMock(Process::class);

        $this->listener = new CliProcessListener(
            hub: $this->hub,
            logger: $this->logger,
        );
    }

    public function testOnCliProcessOutputEvent(): void
    {
        $event = new CliProcessStdOutputEvent(
            output: 'Test output',
            downloadJob: $this->downloadJob,
            process: $this->process,
        );

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) {
                $data = json_decode($update->getData(), true);
                return $update->isPrivate() === false
                    && $data['is_error'] === false
                    && $data['job_id'] === null
                    && $data['output'] === 'Test output';
            }));

        $this->listener->onCliProcessOutputEvent($event);
    }

    public function testOnCliProcessErrorOutputEvent(): void
    {
        $event = new CliProcessErrOutputEvent(
            output: 'Test output',
            downloadJob: $this->downloadJob,
            process: $this->process,
        );
        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) {
                $data = json_decode($update->getData(), true);
                return $update->isPrivate() === false
                    && $data['is_error'] === true
                    && $data['job_id'] === null
                    && $data['output'] === 'Test output';
            }));

        $this->listener->onCliProcessErrorOutputEvent($event);
    }

    public function testSendStdOutputToLogger(): void
    {
        $this->downloadJob->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('CLI Process STDOUT', [
                'job_id' => 123,
                'output' => 'Test output',
            ]);

        $event = new CliProcessStdOutputEvent(
            output: 'Test output',
            downloadJob: $this->downloadJob,
            process: $this->process,
        );
        $this->listener->sendStdOutputToLogger($event);
    }

    public function testSendErrOutputToLogger(): void
    {
        $this->downloadJob->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('CLI Process STDERR', [
                'job_id' => 123,
                'output' => 'Test output',
            ]);

        $event = new CliProcessErrOutputEvent(
            output: 'Test output',
            downloadJob: $this->downloadJob,
            process: $this->process,
        );
        $this->listener->sendErrOutputToLogger($event);
    }
}
