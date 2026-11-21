<?php

namespace App\Mercure;

use App\Entity\OidcSubjectIdentifier;
use App\Service\Settings\CurrentClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells a user's clients that one of the user's settings layers changed (#89), so they re-fetch it instead of polling.
 *
 * Events are small and only say what changed, e.g. {"type":"settings","layer":"site","action":"updated","slug":"vimeo",
 * "sourceClientId":"…"}. They are published privately on the user's settings topic (UserTopics::settings()).
 * sourceClientId is the X-Client-Id of the client that made the change (null if none), so it can skip its own events.
 */
final readonly class SettingsChangePublisher
{
    public const string LAYER_USER = 'user';
    public const string LAYER_DOWNLOADER = 'downloader';
    public const string LAYER_SITE = 'site';
    public const string LAYER_CLIENT = 'client';
    public const string LAYER_CLIENT_PREFERENCES = 'clientPreferences';

    public const string ACTION_UPDATED = 'updated';
    public const string ACTION_DELETED = 'deleted';

    public function __construct(
        private HubInterface $hub,
        private CurrentClient $currentClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param self::LAYER_*        $layer
     * @param array<string,string> $keys   identifies the changed layer: downloader, slug, clientId or clientType
     * @param self::ACTION_*       $action
     */
    public function publish(OidcSubjectIdentifier $owner, string $layer, array $keys = [], string $action = self::ACTION_UPDATED): void
    {
        $event = ['type' => 'settings', 'layer' => $layer, 'action' => $action, ...$keys, 'sourceClientId' => $this->currentClient->get()?->getId()->toRfc4122()];

        try {
            $this->hub->publish(new Update(UserTopics::settings($owner), json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), private: true));
        } catch (\Throwable $e) {
            // The change itself is saved: clients still get it on their next fetch
            $this->logger->warning('Unable to publish a settings change event', ['event' => $event, 'exception' => $e]);
        }
    }
}
