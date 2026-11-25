<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125194436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Failure report forwarding to the FetchDock collector (server#149): settings, consent and forwarding state, upstream comments';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE failure_report_forwarding_settings (id INT NOT NULL, enabled BOOLEAN DEFAULT false NOT NULL, mode VARCHAR(16) DEFAULT \'review\' NOT NULL, accepted_policy_version VARCHAR(64) DEFAULT NULL, accepted_policy_url TEXT DEFAULT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, collector_url TEXT DEFAULT NULL, instance_id UUID DEFAULT NULL, encrypted_instance_secret TEXT DEFAULT NULL, registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE failure_report_upstream_comment (id UUID NOT NULL, comment TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, state VARCHAR(16) NOT NULL, upstream_id UUID DEFAULT NULL, error TEXT DEFAULT NULL, report_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_80DA92324BD2A4C0 ON failure_report_upstream_comment (report_id)');
        $this->addSql('ALTER TABLE failure_report_upstream_comment ADD CONSTRAINT FK_80DA92324BD2A4C0 FOREIGN KEY (report_id) REFERENCES failure_report (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE failure_report ADD share_upstream BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE failure_report ADD upstream_policy_version VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE failure_report ADD forwarding VARCHAR(16) DEFAULT \'not_requested\' NOT NULL');
        $this->addSql('ALTER TABLE failure_report ADD upstream_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE failure_report ADD forwarded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE failure_report ADD upstream_comment TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE failure_report ADD forwarding_error TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_failure_report_forwarding ON failure_report (forwarding)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE failure_report_upstream_comment DROP CONSTRAINT FK_80DA92324BD2A4C0');
        $this->addSql('DROP TABLE failure_report_forwarding_settings');
        $this->addSql('DROP TABLE failure_report_upstream_comment');
        $this->addSql('DROP INDEX idx_failure_report_forwarding');
        $this->addSql('ALTER TABLE failure_report DROP share_upstream');
        $this->addSql('ALTER TABLE failure_report DROP upstream_policy_version');
        $this->addSql('ALTER TABLE failure_report DROP forwarding');
        $this->addSql('ALTER TABLE failure_report DROP upstream_id');
        $this->addSql('ALTER TABLE failure_report DROP forwarded_at');
        $this->addSql('ALTER TABLE failure_report DROP upstream_comment');
        $this->addSql('ALTER TABLE failure_report DROP forwarding_error');
    }
}
