<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119210805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at to download_job and downloaded_file for soft-deletion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE downloaded_file ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job DROP deleted_at');
        $this->addSql('ALTER TABLE downloaded_file DROP deleted_at');
    }
}
