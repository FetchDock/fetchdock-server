<?php

namespace App\Tests\Unit\Service\Downloader\Options;

use App\Service\Downloader\Options\OptionDefinition;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use App\Tests\Double\FakeOptionsDumper;
use PHPUnit\Framework\TestCase;

class OptionsSchemaFactoryTest extends TestCase
{
    use OptionsTestTrait;

    public function testValueOptionsUseTheirLongFlagAndType(): void
    {
        $options = $this->schemaFactory()->getAllOptions('yt-dlp-cli');

        $this->assertSame(OptionDefinition::KIND_VALUE, $options['ratelimit']->kind);
        $this->assertSame('--limit-rate', $options['ratelimit']->flag);
        $this->assertSame('string', $options['ratelimit']->type);
        $this->assertSame('integer', $options['concurrent_fragment_downloads']->type);
        $this->assertSame('number', $options['sleep_interval']->type);
        $this->assertSame(['never', 'ignore', 'warn', 'detect_or_warn', 'force'], $options['fixup']->choices);
    }

    public function testTrueAndFalseFlagsOfOneDestBecomeOneBoolean(): void
    {
        $options = $this->schemaFactory()->getAllOptions('yt-dlp-cli');

        $this->assertSame(OptionDefinition::KIND_BOOLEAN, $options['writesubtitles']->kind);
        $this->assertSame('--write-subs', $options['writesubtitles']->flag);
        $this->assertSame('--no-write-subs', $options['writesubtitles']->falseFlag);
        $this->assertSame('Write subs', $options['writesubtitles']->title);
    }

    public function testFalseOnlyFlagHasNoTrueFlag(): void
    {
        $part = $this->schemaFactory()->getAllOptions('gallery-dl-cli')['part'];

        $this->assertSame(OptionDefinition::KIND_BOOLEAN, $part->kind);
        $this->assertNull($part->flag);
        $this->assertSame('--no-part', $part->falseFlag);
        $this->assertStringStartsWith('false: ', (string) $part->description);
    }

    public function testConstantFlagsAreKeyedByFlag(): void
    {
        $options = $this->schemaFactory()->getAllOptions('yt-dlp-cli');

        $this->assertSame(OptionDefinition::KIND_APPEND, $options['match_filter']->kind);
        $this->assertSame('--match-filters', $options['match_filter']->flag);
        $this->assertSame('--no-match-filters', $options['no-match-filters']->flag);
        $this->assertSame('--zip', $this->schemaFactory()->getAllOptions('gallery-dl-cli')['zip']->flag);
    }

    public function testHiddenAndUnsupportedOptionsAreSkipped(): void
    {
        $options = $this->schemaFactory()->getAllOptions('yt-dlp-cli');

        $this->assertArrayNotHasKey('all-formats', $options, 'hidden');
        $this->assertArrayNotHasKey('exec_cmd', $options, 'callback');
        $this->assertSame('--format', $options['format']->flag);
    }

    public function testDownloadersWithoutToolHaveNoOptions(): void
    {
        $factory = $this->schemaFactory();

        $this->assertFalse($factory->hasOptions('mock'));
        $this->assertSame([], $factory->getAllOptions('mock'));
        $this->assertSame([], $factory->getSchema('mock')['properties']);
        $this->assertNull($factory->getSchema('mock')['x-tool-version']);
    }

    public function testSchemaContainsExposedOptionsOnly(): void
    {
        $schema = $this->schemaFactory()->getSchema('yt-dlp-cli');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('2026.01.01-test', $schema['x-tool-version']);
        $this->assertSame(['type' => 'string', 'maxLength' => 1024, 'title' => 'Download rate limit', 'description' => $schema['properties']['ratelimit']['description'], 'x-flag' => '--limit-rate', 'x-metavar' => 'RATE', 'x-group' => 'Download Options'], $schema['properties']['ratelimit']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 1024], 'maxItems' => 20], array_slice($schema['properties']['match_filter'], 0, 3));
        $this->assertSame('--no-write-subs', $schema['properties']['writesubtitles']['x-flag-false']);

        foreach (['cookiefile', 'download_archive', 'proxy', 'listformats', 'updatetime', 'print_help'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $schema['properties']);
        }
    }

    public function testToolOptionsAreCached(): void
    {
        $factory = $this->schemaFactory();
        $calls = FakeOptionsDumper::$dumpCalls;

        $factory->getSchema('yt-dlp-cli');
        $factory->getSchema('yt-dlp-cli');
        $this->assertSame($calls + 1, FakeOptionsDumper::$dumpCalls);

        $factory->invalidate();
        $factory->getSchema('yt-dlp-cli');
        $this->assertSame($calls + 2, FakeOptionsDumper::$dumpCalls);
    }

    public function testDumpFailureIsReported(): void
    {
        $factory = new OptionsSchemaFactory(
            new class implements \App\Service\Downloader\Options\OptionsDumperInterface {
                public function dump(string $tool): array
                {
                    throw new \RuntimeException('boom');
                }
            },
            new \Symfony\Component\Cache\Adapter\TagAwareAdapter(new \Symfony\Component\Cache\Adapter\ArrayAdapter()),
            $this->policy(),
        );

        $this->expectException(\RuntimeException::class);
        $factory->getSchema('yt-dlp-cli');
    }
}
