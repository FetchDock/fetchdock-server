<?php

namespace App\Tests\Unit\Mercure;

use App\Entity\OidcSubjectIdentifier;
use App\Mercure\SettingsChangePublisher;
use App\Repository\OidcSubjectIdentifierRepository;
use App\Service\Settings\CurrentClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

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

        new SettingsChangePublisher($hub, $currentClient, $logger, $this->createStub(OidcSubjectIdentifierRepository::class))->publish(new OidcSubjectIdentifier(), SettingsChangePublisher::LAYER_SITE, ['slug' => 'vimeo']);
    }

    public function testAdminChangesArePublishedToAllUsersInBatches(): void
    {
        $uuids = array_map(static fn (): Uuid => Uuid::v4(), range(1, SettingsChangePublisher::TOPICS_PER_UPDATE + 1));
        $users = $this->createStub(OidcSubjectIdentifierRepository::class);
        $users->method('iterateUuids')->willReturn(new \ArrayIterator($uuids));
        $currentClient = $this->createStub(CurrentClient::class);
        $currentClient->method('get')->willReturn(null);
        $hub = $this->createMock(HubInterface::class);
        $published = [];
        $hub->expects($this->exactly(2))->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        });

        new SettingsChangePublisher($hub, $currentClient, $this->createStub(LoggerInterface::class), $users)
            ->publishToAllUsers(SettingsChangePublisher::LAYER_SITE_DEFAULTS, ['slug' => 'vimeo']);

        $this->assertSame(
            array_map(static fn (Uuid $uuid): string => '/users/'.$uuid->toRfc4122().'/settings', $uuids),
            [...$published[0]->getTopics(), ...$published[1]->getTopics()],
        );
        $this->assertCount(SettingsChangePublisher::TOPICS_PER_UPDATE, $published[0]->getTopics());
        foreach ($published as $update) {
            $this->assertTrue($update->isPrivate());
            $this->assertSame('{"type":"settings","layer":"siteDefaults","action":"updated","slug":"vimeo","sourceClientId":null}', $update->getData());
        }
    }

    public function testAUserListingFailureIsLogged(): void
    {
        $users = $this->createStub(OidcSubjectIdentifierRepository::class);
        $users->method('iterateUuids')->willThrowException(new \RuntimeException('Database down'));
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Unable to list the users to notify of a settings change');

        new SettingsChangePublisher($hub, $this->createStub(CurrentClient::class), $logger, $users)->publishToAllUsers(SettingsChangePublisher::LAYER_OPTION_POLICY, ['downloader' => 'yt-dlp-cli']);
    }
}
