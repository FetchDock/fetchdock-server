<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a non-sequential uuid to oidc_subject_identifier (used in per-user Mercure topics)';
    }

    public function up(Schema $schema): void
    {
        // Existing users get a UUID before the column becomes NOT NULL (gen_random_uuid() is built in since PostgreSQL 13)
        $this->addSql('ALTER TABLE oidc_subject_identifier ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE oidc_subject_identifier SET uuid = gen_random_uuid() WHERE uuid IS NULL');
        $this->addSql('ALTER TABLE oidc_subject_identifier ALTER uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C8505D6CD17F50A6 ON oidc_subject_identifier (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C8505D6CD17F50A6');
        $this->addSql('ALTER TABLE oidc_subject_identifier DROP uuid');
    }
}
