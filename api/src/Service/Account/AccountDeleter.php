<?php

namespace App\Service\Account;

use App\Entity\OidcSubjectIdentifier;
use App\Mercure\UserTopics;
use App\Message\PurgeFilesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a user's account and everything that belongs to it (server#129, GDPR Art. 17).
 *
 * The database cascades the delete to the user's download jobs (soft-deleted ones included) with their events and file
 * links, settings, clients, client preferences and failure reports. Files the user's jobs had are then purged
 * asynchronously (PurgeFilesMessage), unless another user's job still uses them. A job the worker is running stops when
 * its process ends (ProcessStoppedListener). The IdP account stays: signing in again creates a new, empty account.
 */
final readonly class AccountDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(OidcSubjectIdentifier $owner): void
    {
        $ownerId = $owner->getId();
        $uuid = $owner->getUuid();
        $connection = $this->entityManager->getConnection();

        /** @var list<int> $fileIds */
        $fileIds = $connection->transactional(static function () use ($connection, $ownerId): array {
            $fileIds = array_map('intval', $connection->fetchFirstColumn(
                'SELECT DISTINCT l.downloaded_file_id FROM downloaded_file_download_job l JOIN download_job j ON j.id = l.download_job_id WHERE j.owner_id = ?',
                [$ownerId],
            ));
            $connection->executeStatement('DELETE FROM oidc_subject_identifier WHERE id = ?', [$ownerId]);

            return $fileIds;
        });

        // The deleted rows may still be managed
        $this->entityManager->clear();

        if ([] !== $fileIds) {
            $this->messageBus->dispatch(new PurgeFilesMessage(fileIds: $fileIds));
        }

        $this->publishDeleted($uuid);

        $this->logger->info('Account deleted by its user', ['user_uuid' => $uuid->toRfc4122(), 'files_to_purge' => \count($fileIds)]);
    }

    /**
     * Tells the user's other signed-in clients to sign out, so they don't create a new account right away.
     */
    private function publishDeleted(Uuid $uuid): void
    {
        try {
            $this->hub->publish(new Update(UserTopics::account($uuid), '{"type":"account_deleted"}', private: true));
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to publish the account deletion event', ['exception' => $e]);
        }
    }
}
