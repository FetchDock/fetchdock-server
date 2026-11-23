<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cascades the account delete to download jobs, their events and the user settings.
 */
final class Version20261123161651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion (server#129): deleting an account deletes its download jobs (with their events) and general settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job DROP CONSTRAINT fk_d1ce95a57e3c61f9');
        $this->addSql('ALTER TABLE download_job ADD CONSTRAINT FK_D1CE95A57E3C61F9 FOREIGN KEY (owner_id) REFERENCES oidc_subject_identifier (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE download_job_event DROP CONSTRAINT fk_fb780834c918cb82');
        $this->addSql('ALTER TABLE download_job_event ADD CONSTRAINT FK_FB780834C918CB82 FOREIGN KEY (download_job_id) REFERENCES download_job (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_setting DROP CONSTRAINT fk_c779a6927e3c61f9');
        $this->addSql('ALTER TABLE user_setting ADD CONSTRAINT FK_C779A6927E3C61F9 FOREIGN KEY (owner_id) REFERENCES oidc_subject_identifier (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_job DROP CONSTRAINT FK_D1CE95A57E3C61F9');
        $this->addSql('ALTER TABLE download_job ADD CONSTRAINT fk_d1ce95a57e3c61f9 FOREIGN KEY (owner_id) REFERENCES oidc_subject_identifier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE download_job_event DROP CONSTRAINT FK_FB780834C918CB82');
        $this->addSql('ALTER TABLE download_job_event ADD CONSTRAINT fk_fb780834c918cb82 FOREIGN KEY (download_job_id) REFERENCES download_job (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_setting DROP CONSTRAINT FK_C779A6927E3C61F9');
        $this->addSql('ALTER TABLE user_setting ADD CONSTRAINT fk_c779a6927e3c61f9 FOREIGN KEY (owner_id) REFERENCES oidc_subject_identifier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
