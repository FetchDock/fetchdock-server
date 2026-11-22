<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #116: yt-dlp's .desktop shortcuts are no longer tracked. Their rows go; the files on disk stay as untracked sidecars
 * of their media file (moved along with it). The rows can't come back: nothing links shortcuts anymore.
 */
final class Version20261122142050 extends AbstractMigration
{
    private const string SHORTCUT_FILES = "SELECT f.id FROM downloaded_file f
        JOIN downloaded_file_download_job l ON l.downloaded_file_id = f.id
        JOIN download_job j ON j.id = l.download_job_id
        WHERE f.relative_path LIKE '%.desktop' AND j.downloader = 'yt-dlp-cli'";

    public function getDescription(): string
    {
        return 'Remove the tracked yt-dlp .desktop shortcuts';
    }

    public function up(Schema $schema): void
    {
        // Links and file move items go with the rows (ON DELETE CASCADE)
        $this->addSql('DELETE FROM downloaded_file WHERE id IN ('.self::SHORTCUT_FILES.')');
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the shortcuts were never needed as files of a job
    }
}
