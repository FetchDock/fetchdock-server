<?php

namespace App\Tests\Unit\Mercure;

use App\Entity\OidcSubjectIdentifier;
use App\Mercure\SettingsChangePublisher;
use App\Service\Settings\CurrentClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;

class SettingsChangePublisherTest extends TestCase
{
    public function testAHubFailureIsLoggedAndDoesNotFailTheWrite(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Hub down'));
        $currentClient = $this->createStub(CurrentClient::class);
        $currentClient->method('get')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Unable to publish a settings change event', $this->callback(
            static fn (array $context): bool => 'site' === $context['event']['layer'] && 'vimeo' === $context['event']['slug'] && $context['exception'] instanceof \RuntimeException,
        ));

        new SettingsChangePublisher($hub, $currentClient, $logger)->publish(new OidcSubjectIdentifier(), SettingsChangePublisher::LAYER_SITE, ['slug' => 'vimeo']);
    }
}
