<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #109 PR 2: the site and directory of download jobs, and a download directory per site (ADR 0011).
 * DownloadJob.site is filled by app:jobs:assign-sites.
 */
final class Version20261122121204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site download directories, and the site and directory of download jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job ADD site_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE download_job ADD download_directory_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE download_job ADD CONSTRAINT FK_D1CE95A5F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE download_job ADD CONSTRAINT FK_D1CE95A5E900B0EE FOREIGN KEY (download_directory_id) REFERENCES download_directory (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D1CE95A5F6BD1646 ON download_job (site_id)');
        $this->addSql('CREATE INDEX IDX_D1CE95A5E900B0EE ON download_job (download_directory_id)');
        $this->addSql('ALTER TABLE site ADD download_directory_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E4E900B0EE FOREIGN KEY (download_directory_id) REFERENCES download_directory (id)');
        $this->addSql('CREATE INDEX IDX_694309E4E900B0EE ON site (download_directory_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job DROP CONSTRAINT FK_D1CE95A5F6BD1646');
        $this->addSql('ALTER TABLE download_job DROP CONSTRAINT FK_D1CE95A5E900B0EE');
        $this->addSql('DROP INDEX IDX_D1CE95A5F6BD1646');
        $this->addSql('DROP INDEX IDX_D1CE95A5E900B0EE');
        $this->addSql('ALTER TABLE download_job DROP site_id');
        $this->addSql('ALTER TABLE download_job DROP download_directory_id');
        $this->addSql('ALTER TABLE site DROP CONSTRAINT FK_694309E4E900B0EE');
        $this->addSql('DROP INDEX IDX_694309E4E900B0EE');
        $this->addSql('ALTER TABLE site DROP download_directory_id');
    }
}
