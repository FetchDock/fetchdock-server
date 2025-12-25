<?php

namespace App\EventListener;

use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStdOutputEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class CliProcessListener
{
    private const TOPIC = 'https://example.com/downloadjobs/{id}/process';

    public function __construct(
        private readonly HubInterface    $hub,
        private readonly LoggerInterface $logger,
    )
    {
    }

    #[AsEventListener(event: CliProcessStdOutputEvent::class)]
    public function onCliProcessOutputEvent(CliProcessStdOutputEvent $event): void
    {
        $this->sendToHub([
            'is_error' => $event->isError,
            'job_id' => $event->downloadJob->getId(),
            'output' => $event->output,
        ]);
    }

    #[AsEventListener(event: CliProcessErrOutputEvent::class)]
    public function onCliProcessErrorOutputEvent(CliProcessErrOutputEvent $event): void
    {
        $this->sendToHub([
            'is_error' => $event->isError,
            'job_id' => $event->downloadJob->getId(),
            'output' => $event->output,
        ]);
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

    private function sendToHub(array $data): void
    {
        $update = new Update(
            topics: 'https://example.com/downloadjobs/process',
            data: json_encode($data),
            private: false
        );

        $this->hub->publish($update);
    }

    private function logOutput(string $level, CliProcessStdOutputEvent|CliProcessErrOutputEvent $event): void
    {
        $message = $event->isError ? 'CLI Process STDERR' : 'CLI Process STDOUT';
        $context = [
            'job_id' => $event->downloadJob->getId(),
            'output' => $event->output,
        ];

        match ($level) {
            'info' => $this->logger->info($message, $context),
            'error' => $this->logger->error($message, $context),
            default => $this->logger->debug($message, $context),
        };
    }
}
