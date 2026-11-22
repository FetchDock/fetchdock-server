<?php

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use DoctrineMigrations\Version20261122113306;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the #109 backfill migration against the former schema, in a throwaway schema inside a rolled-back transaction.
 */
class DownloadLocationBackfillTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // Migrations are loaded by doctrine/migrations, not by the autoloader
        require_once \dirname(__DIR__, 3).'/migrations/Version20261122113306.php';

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('CREATE SCHEMA backfill_test');
        $this->connection->executeStatement('SET LOCAL search_path TO backfill_test');
        $this->connection->executeStatement('CREATE TABLE downloaded_file (id INT PRIMARY KEY, path TEXT NOT NULL, metadata JSON NOT NULL, visible BOOLEAN NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->connection->executeStatement('CREATE TABLE downloaded_file_download_job (downloaded_file_id INT NOT NULL REFERENCES downloaded_file (id), download_job_id INT NOT NULL, PRIMARY KEY (downloaded_file_id, download_job_id))');
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testBackfillsLocationsAndMergesDuplicatePaths(): void
    {
        $this->connection->executeStatement("INSERT INTO downloaded_file VALUES
            (1, '/downloads/youtube/a.mp4', '{}', true, NULL),
            (2, '/elsewhere/b.mp4', '{}', true, NULL),
            (3, '/downloads/youtube/c.mp4', '{}', true, NOW()),
            (4, '/downloads/youtube/c.mp4', '{}', true, NULL),
            (5, '/downloads/youtube/c.mp4', '{}', true, NOW())");
        $this->connection->executeStatement('INSERT INTO downloaded_file_download_job VALUES (1, 10), (3, 11), (4, 12), (5, 12), (5, 13)');

        $migration = new Version20261122113306($this->connection, new NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $this->assertSame(
            [['id' => 1, 'relative_path' => 'youtube/a.mp4', 'deleted' => false], ['id' => 2, 'relative_path' => '/elsewhere/b.mp4', 'deleted' => false], ['id' => 4, 'relative_path' => 'youtube/c.mp4', 'deleted' => false]],
            $this->connection->fetchAllAssociative('SELECT f.id, f.relative_path, f.deleted_at IS NOT NULL AS deleted FROM downloaded_file f JOIN download_directory d ON d.id = f.directory_id AND d.is_default ORDER BY f.id'),
            'Every row is in the default directory; the live duplicate survives',
        );
        $this->assertSame(
            [[1, 10], [4, 11], [4, 12], [4, 13]],
            array_map(static fn (array $row): array => array_values($row), $this->connection->fetchAllAssociative('SELECT downloaded_file_id, download_job_id FROM downloaded_file_download_job ORDER BY 1, 2')),
            'Job links of merged rows move to the survivor',
        );
        $this->assertSame([['name' => 'Default', 'path' => '/downloads']], $this->connection->fetchAllAssociative('SELECT name, path FROM download_directory'));
    }
}
