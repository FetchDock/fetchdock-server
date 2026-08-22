<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260820192227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE supported_site ADD downloader VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE supported_site ADD downloader_specific_identifier VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE supported_site ADD downloader_specific_sub_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supported_site ADD downloader_metadata JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE supported_site DROP downloader');
        $this->addSql('ALTER TABLE supported_site DROP downloader_specific_identifier');
        $this->addSql('ALTER TABLE supported_site DROP downloader_specific_sub_identifier');
        $this->addSql('ALTER TABLE supported_site DROP downloader_metadata');
    }
}
