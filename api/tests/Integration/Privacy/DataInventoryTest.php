<?php

namespace App\Tests\Integration\Privacy;

use App\Service\Privacy\PolicyTextVersion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps privacy/data-inventory.yaml in sync with the Doctrine mappings (ADR 0013): stored data can't ship without
 * being classified for the generated privacy policy.
 */
final class DataInventoryTest extends KernelTestCase
{
    private const array CATEGORIES = ['account', 'settings', 'request', 'content', 'device', 'report', 'technical'];
    private const array RETENTIONS = ['with_account', 'with_job', 'until_completed', 'with_file', 'until_deleted', 'until_withdrawn', 'derived', 'transient'];
    private const array AUDIENCES = ['owner', 'view_all', 'admins', 'nobody'];
    private const array CLASSIFICATION_KEYS = ['personal', 'category', 'purpose', 'retention', 'visibleTo'];

    /** @var array<string, mixed> */
    private static array $inventory;

    public static function setUpBeforeClass(): void
    {
        self::$inventory = Yaml::parseFile(\dirname(__DIR__, 3).'/'.PolicyTextVersion::INVENTORY_FILE);
    }

    public function testEveryMappedFieldIsClassifiedAndNothingElse(): void
    {
        self::bootKernel();
        $mapped = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $mapped[$metadata->getName()] = self::storedFields($metadata);
        }
        ksort($mapped);

        $listed = array_map(static fn (array $entity): array => array_keys($entity['fields'] ?? []), self::$inventory['entities']);
        ksort($listed);

        $problems = [];
        foreach (array_diff_key($mapped, $listed) as $class => $fields) {
            $problems[] = "add the entity $class";
        }
        foreach (array_diff_key($listed, $mapped) as $class => $fields) {
            $problems[] = "remove the entity $class, it is no longer mapped";
        }
        foreach (array_intersect_key($mapped, $listed) as $class => $fields) {
            foreach (array_diff($fields, $listed[$class]) as $field) {
                $problems[] = "classify $class::$field";
            }
            foreach (array_diff($listed[$class], $fields) as $field) {
                $problems[] = "remove $class::$field, it is not a column or the owning side of an association";
            }
        }
        self::assertSame([], $problems, 'Update '.PolicyTextVersion::INVENTORY_FILE);
    }

    public function testEveryEntryIsFullyClassified(): void
    {
        foreach (self::$inventory['entities'] as $class => $entity) {
            self::assertNotSame('', trim((string) ($entity['summary'] ?? '')), "$class needs a summary");
            $defaults = $entity['defaults'] ?? [];
            self::assertIsArray($defaults, "$class needs defaults");
            foreach ($entity['fields'] ?? [] as $field => $overrides) {
                self::assertTrue(null === $overrides || \is_array($overrides), "$class::$field is ~ or a map");
                self::assertSame([], array_diff(array_keys($overrides ?? []), self::CLASSIFICATION_KEYS), "$class::$field has unknown keys");
                self::assertClassification(array_merge($defaults, $overrides ?? []), "$class::$field");
            }
        }
    }

    public function testDataOutsideTheDatabaseIsClassified(): void
    {
        $ids = [];
        foreach (['storage', 'outgoingConnections'] as $section) {
            self::assertNotEmpty(self::$inventory[$section] ?? [], "The inventory lists $section");
            foreach (self::$inventory[$section] as $entry) {
                $id = (string) ($entry['id'] ?? '');
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $id, "Every $section entry has an id");
                self::assertNotContains($id, $ids, "Duplicate id $id");
                $ids[] = $id;
                self::assertIsBool($entry['personal'] ?? null, "$id says whether it is personal");
                $required = 'storage' === $section ? ['what', 'where', 'purpose'] : ['to', 'when', 'sends'];
                foreach ($required as $key) {
                    self::assertNotSame('', trim((string) ($entry[$key] ?? '')), "$id needs $key");
                }
                if ('storage' === $section && $entry['personal']) {
                    self::assertContains($entry['category'] ?? null, self::CATEGORIES, "$id has a known category");
                    self::assertRetention($entry['retention'] ?? null, $id);
                }
            }
        }
    }

    /**
     * Columns and owning-side associations; inverse sides store nothing of their own.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private static function storedFields(ClassMetadata $metadata): array
    {
        $fields = array_keys($metadata->fieldMappings);
        foreach ($metadata->associationMappings as $name => $association) {
            if ($association->isOwningSide()) {
                $fields[] = $name;
            }
        }
        sort($fields);

        return $fields;
    }

    /**
     * @param array<string, mixed> $classification
     */
    private static function assertClassification(array $classification, string $label): void
    {
        self::assertIsBool($classification['personal'] ?? null, "$label says whether it is personal");
        if (!$classification['personal']) {
            return;
        }
        self::assertContains($classification['category'] ?? null, self::CATEGORIES, "$label has a known category");
        self::assertNotSame('', trim((string) ($classification['purpose'] ?? '')), "$label has a purpose");
        self::assertRetention($classification['retention'] ?? null, $label);
        $audience = $classification['visibleTo'] ?? null;
        self::assertIsArray($audience, "$label lists who can see it");
        self::assertNotEmpty($audience, "$label lists who can see it");
        self::assertSame([], array_values(array_diff($audience, self::AUDIENCES)), "$label has known audiences");
        if (\in_array('nobody', $audience, true)) {
            self::assertCount(1, $audience, "$label: nobody excludes other audiences");
        }
    }

    private static function assertRetention(mixed $retention, string $label): void
    {
        self::assertTrue(
            \in_array($retention, self::RETENTIONS, true) || (\is_string($retention) && preg_match('/^operator:[a-z_]+$/', $retention)),
            "$label has a known retention rule",
        );
    }
}
