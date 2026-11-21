<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #94 phase 1: canonical media keys for duplicate detection across URL spellings, and per-site
 * canonicalization settings (config/sites.yaml). Existing jobs are filled by app:jobs:canonicalize.
 */
final class Version20261121114344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Canonical media keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job ADD canonical_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_download_job_canonical_key ON download_job (canonical_key)');
        $this->addSql('CREATE INDEX idx_download_job_owner_canonical_key ON download_job (owner_id, canonical_key)');
        $this->addSql('ALTER TABLE site ADD canonicalization JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_download_job_canonical_key');
        $this->addSql('DROP INDEX idx_download_job_owner_canonical_key');
        $this->addSql('ALTER TABLE download_job DROP canonical_key');
        $this->addSql('ALTER TABLE site DROP canonicalization');
    }
}
