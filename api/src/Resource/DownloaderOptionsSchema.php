<?php

namespace App\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\DownloaderOptionsSchemaProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * JSON Schema (draft 2020-12) of the options users may configure for a downloader, in
 * UserDownloaderSettings.options and UserSiteSettings.downloaderOptions.
 *
 * Properties are keyed by option ("dest" of the tool's option parser) and carry:
 * - x-flag: the command line flag passed for a value (as "--flag=value", once per item for arrays)
 *   or for true; absent if true keeps the tool default
 * - x-flag-false: the flag passed for false; absent if false keeps the tool default
 * - x-group: the tool's option group, x-metavar: the tool's value placeholder
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/downloaders/{id}/options-schema',
            description: 'JSON Schema of the configurable options of the downloader. Options not in the schema are rejected.',
            security: "is_granted('ROLE_USER')",
            provider: DownloaderOptionsSchemaProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['downloader_options_schema:read'], 'preserve_empty_objects' => true, 'skip_null_values' => false],
)]
final class DownloaderOptionsSchema
{
    /** Downloader identifier (see /downloaders) */
    #[ApiProperty(identifier: true)]
    public string $id;

    #[SerializedName('$schema')]
    #[Groups(['downloader_options_schema:read'])]
    public string $schema = 'https://json-schema.org/draft/2020-12/schema';

    #[SerializedName('$id')]
    #[Groups(['downloader_options_schema:read'])]
    public string $schemaId;

    #[Groups(['downloader_options_schema:read'])]
    public string $title;

    #[Groups(['downloader_options_schema:read'])]
    public string $type = 'object';

    #[Groups(['downloader_options_schema:read'])]
    public bool $additionalProperties = false;

    /** Option schemas by option key */
    #[ApiProperty(openapiContext: ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => true]])]
    #[Groups(['downloader_options_schema:read'])]
    public \ArrayObject $properties;

    /** Installed version of the downloader's tool, null for downloaders without options */
    #[SerializedName('x-tool-version')]
    #[Groups(['downloader_options_schema:read'])]
    public ?string $toolVersion = null;

    /**
     * @param array<string, mixed> $schema see App\Service\Downloader\Options\OptionsSchemaFactory::getSchema()
     */
    public static function fromSchema(string $downloader, array $schema): self
    {
        $resource = new self();
        $resource->id = $downloader;
        $resource->schemaId = $schema['$id'];
        $resource->title = $schema['title'];
        $resource->properties = new \ArrayObject($schema['properties']);
        $resource->toolVersion = $schema['x-tool-version'];

        return $resource;
    }
}
