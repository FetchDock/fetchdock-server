<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prefix the user download stats view with "view_", so the DBAL schema_filter (~^(?!view_)~) excludes it
 * and schema tools (doctrine:migrations:diff, doctrine:schema:validate) no longer treat it as a missing table.
 *
 * App\Resource\Stats\UserDownloadStats is mapped to the new name with #[ORM\Table].
 */
final class Version20261119223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename view user_download_stats to view_user_download_stats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER VIEW user_download_stats RENAME TO view_user_download_stats');
        $this->addSql('ALTER RULE ignore_delete_user_download_stats ON view_user_download_stats RENAME TO ignore_delete_view_user_download_stats');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER RULE ignore_delete_view_user_download_stats ON view_user_download_stats RENAME TO ignore_delete_user_download_stats');
        $this->addSql('ALTER VIEW view_user_download_stats RENAME TO user_download_stats');
    }
}
