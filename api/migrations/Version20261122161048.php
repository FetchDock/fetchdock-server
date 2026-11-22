<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #119: failure reports sent by clients (ADR 0012).
 */
final class Version20261122161048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add failure reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE failure_report (id UUID NOT NULL, kind VARCHAR(32) NOT NULL, url TEXT NOT NULL, user_agent VARCHAR(512) DEFAULT NULL, referrer TEXT DEFAULT NULL, diagnostics JSONB DEFAULT NULL, comment TEXT DEFAULT NULL, host VARCHAR(255) NOT NULL, canonical_key VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, admin_note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id INT NOT NULL, download_job_id INT DEFAULT NULL, site_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_failure_report_owner_canonical_key ON failure_report (owner_id, canonical_key)');
        $this->addSql('CREATE INDEX idx_failure_report_created_at ON failure_report (created_at)');
        $this->addSql('CREATE INDEX IDX_6FBAA8CC7E3C61F9 ON failure_report (owner_id)');
        $this->addSql('CREATE INDEX IDX_6FBAA8CCC918CB82 ON failure_report (download_job_id)');
        $this->addSql('CREATE INDEX IDX_6FBAA8CCF6BD1646 ON failure_report (site_id)');
        $this->addSql('ALTER TABLE failure_report ADD CONSTRAINT FK_6FBAA8CC7E3C61F9 FOREIGN KEY (owner_id) REFERENCES oidc_subject_identifier (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE failure_report ADD CONSTRAINT FK_6FBAA8CCC918CB82 FOREIGN KEY (download_job_id) REFERENCES download_job (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE failure_report ADD CONSTRAINT FK_6FBAA8CCF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE failure_report DROP CONSTRAINT FK_6FBAA8CC7E3C61F9');
        $this->addSql('ALTER TABLE failure_report DROP CONSTRAINT FK_6FBAA8CCC918CB82');
        $this->addSql('ALTER TABLE failure_report DROP CONSTRAINT FK_6FBAA8CCF6BD1646');
        $this->addSql('DROP TABLE failure_report');
    }
}
