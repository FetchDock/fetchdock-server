<?php

namespace App\Mercure;

use App\Entity\OidcSubjectIdentifier;
use App\Repository\OidcSubjectIdentifierRepository;
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
 *
 * Admin changes that affect every user's effective settings (site defaults, downloader option policies, #101) are
 * published on every user's settings topic with publishToAllUsers().
 */
final readonly class SettingsChangePublisher
{
    public const string LAYER_USER = 'user';
    public const string LAYER_DOWNLOADER = 'downloader';
    public const string LAYER_SITE = 'site';
    public const string LAYER_CLIENT = 'client';
    public const string LAYER_CLIENT_PREFERENCES = 'clientPreferences';
    /** Admin-managed site defaults (SiteSetting), keyed by slug */
    public const string LAYER_SITE_DEFAULTS = 'siteDefaults';
    /** Admin downloader option policy overrides (DownloaderOptionPolicy), keyed by downloader */
    public const string LAYER_OPTION_POLICY = 'optionPolicy';

    public const string ACTION_UPDATED = 'updated';
    public const string ACTION_DELETED = 'deleted';

    /**
     * Topics per update of publishToAllUsers(). A private update reaches every subscriber whose token covers at least
     * one of its topics, and subscribers never see the update's topics, so one update can serve many users.
     */
    public const int TOPICS_PER_UPDATE = 200;

    public function __construct(
        private HubInterface $hub,
        private CurrentClient $currentClient,
        private LoggerInterface $logger,
        private OidcSubjectIdentifierRepository $users,
    ) {
    }

    /**
     * @param self::LAYER_*        $layer
     * @param array<string,string> $keys   identifies the changed layer: downloader, slug, clientId or clientType
     * @param self::ACTION_*       $action
     */
    public function publish(OidcSubjectIdentifier $owner, string $layer, array $keys = [], string $action = self::ACTION_UPDATED): void
    {
        $this->doPublish([UserTopics::settings($owner)], $layer, $keys, $action);
    }

    /**
     * Publishes an admin change on every user's settings topic, in updates of up to TOPICS_PER_UPDATE topics.
     *
     * @param self::LAYER_*        $layer
     * @param array<string,string> $keys   identifies the changed layer: slug or downloader
     * @param self::ACTION_*       $action
     */
    public function publishToAllUsers(string $layer, array $keys = [], string $action = self::ACTION_UPDATED): void
    {
        $topics = [];
        try {
            foreach ($this->users->iterateUuids() as $uuid) {
                $topics[] = UserTopics::settings($uuid);
                if (\count($topics) >= self::TOPICS_PER_UPDATE) {
                    $this->doPublish($topics, $layer, $keys, $action);
                    $topics = [];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to list the users to notify of a settings change', ['layer' => $layer, 'keys' => $keys, 'exception' => $e]);
        }
        if ([] !== $topics) {
            $this->doPublish($topics, $layer, $keys, $action);
        }
    }

    /**
     * @param non-empty-list<string> $topics
     * @param array<string,string>   $keys
     */
    private function doPublish(array $topics, string $layer, array $keys, string $action): void
    {
        $event = ['type' => 'settings', 'layer' => $layer, 'action' => $action, ...$keys, 'sourceClientId' => $this->currentClient->get()?->getId()->toRfc4122()];

        try {
            $this->hub->publish(new Update($topics, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), private: true));
        } catch (\Throwable $e) {
            // The change itself is saved: clients still get it on their next fetch
            $this->logger->warning('Unable to publish a settings change event', ['event' => $event, 'exception' => $e]);
        }
    }
}
