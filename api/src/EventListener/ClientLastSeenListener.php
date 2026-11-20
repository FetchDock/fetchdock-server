<?php

namespace App\EventListener;

use App\Service\Settings\CurrentClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks the lastSeenAt of clients that send X-Client-Id on authenticated requests.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class ClientLastSeenListener
{
    public function __construct(
        private CurrentClient $currentClient,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->headers->has(CurrentClient::HEADER)) {
            $this->currentClient->get();
        }
    }
}
