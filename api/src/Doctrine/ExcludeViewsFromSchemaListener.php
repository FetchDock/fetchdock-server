<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Removes entities mapped to database views (named "view_*") from the schema the ORM generates from mappings.
 *
 * The DBAL schema_filter (~^(?!view_)~, see doctrine.yaml) only hides views in the database schema. Without this
 * listener, doctrine:schema:validate and doctrine:schema:update would still try to create a table for read-only
 * entities such as App\Resource\Stats\UserDownloadStats.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class ExcludeViewsFromSchemaListener
{
    public const string VIEW_PREFIX = 'view_';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        foreach ($schema->getTables() as $table) {
            /** @var OptionallyQualifiedName $name */
            $name = $table->getObjectName();
            if (str_starts_with($name->getUnqualifiedName()->getValue(), self::VIEW_PREFIX)) {
                $schema->dropTable($name->toString());
            }
        }
    }
}
