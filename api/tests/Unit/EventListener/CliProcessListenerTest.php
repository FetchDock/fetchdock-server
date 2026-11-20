<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStartEvent;
use App\Event\CliProcessStdOutputEvent;
use App\Event\CliProcessStopEvent;
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
    private Process $process;
    private CliProcessListener $listener;
    private OidcSubjectIdentifier $owner;
    private DownloadJob $downloadJob;
    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->method('publish')->willReturnCallback(function (Update $update) {
            $this->published[] = $update;

            return 'urn:uuid:update';
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->process = $this->createStub(Process::class);
        $this->listener = new CliProcessListener(hub: $this->hub, logger: $this->logger);

        $this->owner = new OidcSubjectIdentifier()->setSubject('user');
        $this->downloadJob = new DownloadJob()->setUri('https://example.com/video')->setOwner($this->owner);
    }

    public function testProgressIsPublishedPrivatelyToTheOwnersOutputTopic(): void
    {
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]  42.5% of    2.72MiB at    2.10MiB/s ETA 00:12\n"));

        $this->assertCount(1, $this->published);
        $update = $this->published[0];
        $this->assertTrue($update->isPrivate());
        $this->assertSame([$this->expectedTopic()], $update->getTopics());
        $this->assertSame([
            'job' => $this->downloadJob->getUuid()->toRfc4122(),
            'type' => 'progress',
            'percent' => 42.5,
            'size' => '2.72MiB',
            'speed' => '2.10MiB/s',
            'eta' => '00:12',
        ], json_decode($update->getData(), true));
    }

    public function testErrorsArePublished(): void
    {
        $this->listener->onCliProcessErrorOutputEvent($this->stdErr("ERROR: [youtube] abc: Requested format is not available\n"));

        $this->assertCount(1, $this->published);
        $this->assertSame(
            ['job' => $this->downloadJob->getUuid()->toRfc4122(), 'type' => 'error', 'message' => '[youtube] abc: Requested format is not available'],
            json_decode($this->published[0]->getData(), true)
        );
    }

    public function testRawOutputIsNotPublished(): void
    {
        $this->listener->onCliProcessOutputEvent($this->stdOut(
            "[debug] Command-line config: ['--config', '/app/config/yt-dlp/yt-dlp.conf', '--verbose', 'https://example.com/video']\n"
            ."[generic] Extracting URL: https://example.com/video\n"
            ."[download] Destination: /downloads/generic/example.com/video.mp4\n"
        ));

        $this->assertSame([], $this->published);
    }

    public function testProgressIsPublishedOnlyWhenTheWholePercentChanges(): void
    {
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]  10.0% of 1.00MiB at 1.00MiB/s ETA 00:09\n[download]  10.7% of 1.00MiB at 1.00MiB/s ETA 00:09\n"));
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]  11.2% of 1.00MiB at 1.00MiB/s ETA 00:08\r[download] 100% of 1.00MiB in 00:00:01 at 1.00MiB/s\n"));

        $percentages = array_map(fn (Update $update) => json_decode($update->getData(), true)['percent'], $this->published);
        $this->assertSame([10.0, 11.2, 100.0], $percentages);
    }

    public function testProgressOfTheNextFileIsPublishedFromZero(): void
    {
        // e.g. video and audio downloaded separately: the second file starts again at 0%
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]  80.0% of 10.00MiB at 1.00MiB/s ETA 00:02\n[download] 100% of 10.00MiB in 00:00:10 at 1.00MiB/s\n"));
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]   0.0% of 2.00MiB at 1.00MiB/s ETA 00:02\n[download]  50.0% of 2.00MiB at 1.00MiB/s ETA 00:01\n[download]  50.4% of 2.00MiB at 1.00MiB/s ETA 00:01\n"));

        $progress = array_map(fn (Update $update) => [json_decode($update->getData(), true)['size'], json_decode($update->getData(), true)['percent']], $this->published);
        $this->assertSame([['10.00MiB', 80.0], ['10.00MiB', 100.0], ['2.00MiB', 0.0], ['2.00MiB', 50.0]], $progress);
    }

    public function testStartAndStopPublishStateEvents(): void
    {
        $this->listener->onCliProcessStartEvent(new CliProcessStartEvent(command: 'yt-dlp', downloadJob: $this->downloadJob, process: $this->process));
        $this->listener->onCliProcessStopEvent(new CliProcessStopEvent($this->downloadJob, true, $this->process, 0, 'OK'));
        $this->listener->onCliProcessStopEvent(new CliProcessStopEvent($this->downloadJob, false, $this->process, 1, 'General error'));

        $states = array_map(fn (Update $update) => json_decode($update->getData(), true)['state'], $this->published);
        $this->assertSame(['in_progress', 'completed', 'failed'], $states);
        foreach ($this->published as $update) {
            $this->assertTrue($update->isPrivate());
            $this->assertSame([$this->expectedTopic()], $update->getTopics());
        }
    }

    public function testJobWithoutOwnerIsNotPublished(): void
    {
        $this->downloadJob->setOwner(null);

        $this->listener->onCliProcessStartEvent(new CliProcessStartEvent(command: 'yt-dlp', downloadJob: $this->downloadJob, process: $this->process));
        $this->listener->onCliProcessOutputEvent($this->stdOut("[download]  42.5% of 2.72MiB at 2.10MiB/s ETA 00:12\n"));

        $this->assertSame([], $this->published);
    }

    public function testUnavailableHubDoesNotFailTheDownload(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Hub unavailable'));
        $this->logger->expects($this->once())->method('warning')->with('Failed to publish download job progress');
        $listener = new CliProcessListener(hub: $hub, logger: $this->logger);

        $listener->onCliProcessStartEvent(new CliProcessStartEvent(command: 'yt-dlp', downloadJob: $this->downloadJob, process: $this->process));
    }

    public function testSendStdOutputToLogger(): void
    {
        $downloadJob = $this->createMock(DownloadJobInterface::class);
        $downloadJob->expects($this->once())->method('getId')->willReturn(123);
        $this->logger->expects($this->once())->method('info')->with('CLI Process STDOUT', ['job_id' => 123, 'output' => 'Test output']);

        $this->listener->sendStdOutputToLogger(new CliProcessStdOutputEvent(output: 'Test output', downloadJob: $downloadJob, process: $this->process));
    }

    public function testSendErrOutputToLogger(): void
    {
        $downloadJob = $this->createMock(DownloadJobInterface::class);
        $downloadJob->expects($this->once())->method('getId')->willReturn(123);
        $this->logger->expects($this->once())->method('error')->with('CLI Process STDERR', ['job_id' => 123, 'output' => 'Test output']);

        $this->listener->sendErrOutputToLogger(new CliProcessErrOutputEvent(output: 'Test output', downloadJob: $downloadJob, process: $this->process));
    }

    private function expectedTopic(): string
    {
        return '/users/'.$this->owner->getUuid()->toRfc4122().'/download_jobs/'.$this->downloadJob->getUuid()->toRfc4122().'/output';
    }

    private function stdOut(string $output): CliProcessStdOutputEvent
    {
        return new CliProcessStdOutputEvent(output: $output, downloadJob: $this->downloadJob, process: $this->process);
    }

    private function stdErr(string $output): CliProcessErrOutputEvent
    {
        return new CliProcessErrOutputEvent(output: $output, downloadJob: $this->downloadJob, process: $this->process);
    }
}
