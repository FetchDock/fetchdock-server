<?php

namespace App\Repository;

use App\Entity\PrivacyPolicySettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivacyPolicySettings>
 */
class PrivacyPolicySettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivacyPolicySettings::class);
    }

    /**
     * The settings row, created if it doesn't exist yet.
     */
    public function get(?LockMode $lockMode = null): PrivacyPolicySettings
    {
        $settings = $this->find(PrivacyPolicySettings::ID, $lockMode);
        if (null === $settings) {
            $this->ensureExists();
            $settings = $this->find(PrivacyPolicySettings::ID, $lockMode);
        }
        if (!$settings instanceof PrivacyPolicySettings) {
            throw new \LogicException('The privacy policy settings row is missing');
        }

        return $settings;
    }

    /**
     * Records that a user who may view all downloads (without being an administrator) signed in.
     */
    public function markViewAllRoleSeen(\DateTimeImmutable $now): void
    {
        $this->ensureExists();
        $this->getEntityManager()->getConnection()->update(
            'privacy_policy_settings',
            ['view_all_role_seen_at' => $now],
            ['id' => PrivacyPolicySettings::ID],
            ['view_all_role_seen_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Inserts the row without racing a concurrent request that does the same */
    private function ensureExists(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO privacy_policy_settings (id, answers) VALUES (:id, '{}') ON CONFLICT (id) DO NOTHING",
            ['id' => PrivacyPolicySettings::ID],
        );
    }
}
