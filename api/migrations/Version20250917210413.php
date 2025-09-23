<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250917210413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE download_job ADD uuid UUID');
        $this->addSql('ALTER TABLE download_job ADD token VARCHAR(64)');
        
        // For existing records, we'll generate default values
        // In production, you might want to migrate existing records differently
        $this->addSql("UPDATE download_job SET uuid = gen_random_uuid() WHERE uuid IS NULL");
        $this->addSql("UPDATE download_job SET token = 'migrated_' || md5(random()::text) WHERE token IS NULL");
        
        // Now make the columns NOT NULL
        $this->addSql('ALTER TABLE download_job ALTER uuid SET NOT NULL');
        $this->addSql('ALTER TABLE download_job ALTER token SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D1CE95A5D17F50A6 ON download_job (uuid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_D1CE95A5D17F50A6');
        $this->addSql('ALTER TABLE download_job DROP uuid');
        $this->addSql('ALTER TABLE download_job DROP token');
    }
}
