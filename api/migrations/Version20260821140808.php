<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821140808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create view for User Download Stats';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<EOL
CREATE VIEW user_download_stats AS
SELECT
    owner_id,
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE state = 0) AS pending,
    COUNT(*) FILTER (WHERE state = 1) AS in_progress,
    COUNT(*) FILTER (WHERE state = 2) AS completed,
    COUNT(*) FILTER (WHERE state = 3) AS failed,
    COUNT(*) FILTER (WHERE state = 4) AS canceled,
    COUNT(*) FILTER (WHERE state = 5) AS already_exists
FROM
    download_job
GROUP BY
    owner_id;
EOL
);
        $this->addSql(<<<EOL
CREATE RULE ignore_delete_user_download_stats AS ON DELETE TO user_download_stats
DO INSTEAD NOTHING;
EOL
);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP VIEW user_download_stats');
    }
}
