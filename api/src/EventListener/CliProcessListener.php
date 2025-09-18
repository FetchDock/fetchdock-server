<?php

namespace App\EventListener;

use App\Event\CliProcessErrOutputEvent;
use App\Event\CliProcessStdOutputEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class CliProcessListener
{
    private const TOPIC = 'https://example.com/downloadjobs/{id}/process';

    public function __construct(
        private HubInterface $hub,
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

    private function sendToHub(array $data): void
    {
        $update = new Update(
            topics: 'https://example.com/downloadjobs/process',
            data: json_encode($data),
            private: false
        );

        $this->hub->publish($update);
    }
}
