<?php

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\ExcludeViewsFromSchemaListener;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use PHPUnit\Framework\TestCase;

class ExcludeViewsFromSchemaListenerTest extends TestCase
{
    public function testRemovesTablesForViewsFromGeneratedSchema(): void
    {
        $schema = new Schema();
        $schema->createTable('download_job')->addColumn('id', 'integer');
        $schema->createTable('view_user_download_stats')->addColumn('owner_id', 'integer');
        $schema->createTable('preview_image')->addColumn('id', 'integer');

        new ExcludeViewsFromSchemaListener()->postGenerateSchema(
            new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema)
        );

        $this->assertTrue($schema->hasTable('download_job'));
        $this->assertTrue($schema->hasTable('preview_image'), 'Only names starting with "view_" are views');
        $this->assertFalse($schema->hasTable('view_user_download_stats'));
    }
}
