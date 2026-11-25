<?php

namespace App\Repository;

use App\Entity\FailureReportForwardingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FailureReportForwardingSettings>
 */
class FailureReportForwardingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FailureReportForwardingSettings::class);
    }

    /**
     * The settings row, created if it doesn't exist yet.
     */
    public function get(?LockMode $lockMode = null): FailureReportForwardingSettings
    {
        $settings = $this->find(FailureReportForwardingSettings::ID, $lockMode);
        if (null === $settings) {
            // Inserts the row without racing a concurrent request that does the same
            $this->getEntityManager()->getConnection()->executeStatement(
                'INSERT INTO failure_report_forwarding_settings (id, enabled, mode) VALUES (:id, false, :mode) ON CONFLICT (id) DO NOTHING',
                ['id' => FailureReportForwardingSettings::ID, 'mode' => 'review'],
            );
            $settings = $this->find(FailureReportForwardingSettings::ID, $lockMode);
        }
        if (!$settings instanceof FailureReportForwardingSettings) {
            throw new \LogicException('The failure report forwarding settings row is missing');
        }

        return $settings;
    }
}
