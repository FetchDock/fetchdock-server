<?php

namespace App\EventListener;

use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStartEvent;
use App\Event\CliProcessStdOutputEvent;
use App\Event\CliProcessStopEvent;
use App\Mercure\DownloadProgressParser;
use App\Mercure\UserTopics;
use App\Model\DownloadJobInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes structured progress of download jobs to their owner's private Mercure topic, and logs the raw output.
 *
 * Clients receive compact events (state, progress, errors), never the raw downloader output, which contains server
 * details such as command lines and paths. The full output stays available through the job's events.
 */
class CliProcessListener
{
    /**
     * Last published progress per job (whole percent, and the size of the file being downloaded), to avoid publishing
     * every progress line. A job can download several files (e.g. video and audio), each starting again at 0%.
     *
     * @var array<string, array{percent: int, size: ?string}>
     */
    private array $lastPublishedProgress = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: CliProcessStartEvent::class)]
    public function onCliProcessStartEvent(CliProcessStartEvent $event): void
    {
        $this->publish($event->downloadJob, ['type' => 'state', 'state' => 'in_progress']);
    }

    #[AsEventListener(event: CliProcessStopEvent::class)]
    public function onCliProcessStopEvent(CliProcessStopEvent $event): void
    {
        $this->publish($event->downloadJob, ['type' => 'state', 'state' => $event->wasSuccessful ? 'completed' : 'failed']);
        unset($this->lastPublishedProgress[(string) $event->downloadJob->getUuid()]);
    }

    #[AsEventListener(event: CliProcessStdOutputEvent::class)]
    public function onCliProcessOutputEvent(CliProcessStdOutputEvent $event): void
    {
        $this->publishOutput($event->downloadJob, $event->output);
    }

    #[AsEventListener(event: CliProcessErrOutputEvent::class)]
    public function onCliProcessErrorOutputEvent(CliProcessErrOutputEvent $event): void
    {
        $this->publishOutput($event->downloadJob, $event->output);
    }

    #[AsEventListener(event: CliProcessStdOutputEvent::class)]
    public function sendStdOutputToLogger(CliProcessStdOutputEvent $event): void
    {
        $this->logOutput('info', $event);
    }

    #[AsEventListener(event: CliProcessErrOutputEvent::class)]
    public function sendErrOutputToLogger(CliProcessErrOutputEvent $event): void
    {
        $this->logOutput('error', $event);
    }

    private function publishOutput(?DownloadJobInterface $downloadJob, string $output): void
    {
        if (null === $downloadJob) {
            return;
        }

        $jobKey = (string) $downloadJob->getUuid();
        foreach (DownloadProgressParser::parse($output) as $event) {
            if ('progress' === $event['type']) {
                $percent = (int) floor($event['percent']);
                $last = $this->lastPublishedProgress[$jobKey] ?? null;
                // Skip unless the whole percent increased, or another file started (different size)
                if (null !== $last && $last['size'] === $event['size'] && $last['percent'] >= $percent) {
                    continue;
                }
                $this->lastPublishedProgress[$jobKey] = ['percent' => $percent, 'size' => $event['size']];
            }
            $this->publish($downloadJob, $event);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function publish(?DownloadJobInterface $downloadJob, array $event): void
    {
        $topic = null === $downloadJob ? null : UserTopics::downloadJobOutput($downloadJob);
        if (null === $topic) {
            // Jobs without an owner (or not persisted, e.g. app:url:download) have no topic to publish to
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: $topic,
                data: json_encode(['job' => $downloadJob->getUuid()->toRfc4122()] + $event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                private: true,
            ));
        } catch (\Throwable $e) {
            // Live updates are best effort: an unavailable hub must not fail the download
            $this->logger->warning('Failed to publish download job progress', ['topic' => $topic, 'exception' => $e]);
        }
    }

    private function logOutput(string $level, CliProcessStdOutputEvent|CliProcessErrOutputEvent $event): void
    {
        $message = $event->isError ? 'CLI Process STDERR' : 'CLI Process STDOUT';
        $context = [
            'job_id' => $event->downloadJob?->getId(),
            'output' => $event->output,
        ];

        match ($level) {
            'info' => $this->logger->info($message, $context),
            'error' => $this->logger->error($message, $context),
            default => $this->logger->debug($message, $context),
        };
    }
}
