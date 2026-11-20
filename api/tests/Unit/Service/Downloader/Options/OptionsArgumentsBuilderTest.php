<?php

namespace App\Tests\Unit\Service\Downloader\Options;

use App\Service\Downloader\Options\OptionsArgumentsBuilder;
use App\Service\Downloader\Options\OptionsDumperInterface;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class OptionsArgumentsBuilderTest extends TestCase
{
    use OptionsTestTrait;

    public function testValuesBecomeSingleFlagValueElements(): void
    {
        $this->assertSame(
            ['--concurrent-fragments=4', '--limit-rate=2M', '--sleep-interval=1.5', '--format=-bv*+ba --exec rm', '--fixup=warn'],
            $this->builder()->build('yt-dlp-cli', [
                'fixup' => 'warn',
                'format' => '-bv*+ba --exec rm',
                'ratelimit' => '2M',
                'concurrent_fragment_downloads' => 4,
                'sleep_interval' => 1.5,
            ]),
        );
        $this->assertSame(['--sleep-interval=2.0'], $this->builder()->build('yt-dlp-cli', ['sleep_interval' => 2.0]));
    }

    public function testBooleansBecomeBareFlags(): void
    {
        $this->assertSame(['--write-subs'], $this->builder()->build('yt-dlp-cli', ['writesubtitles' => true]));
        $this->assertSame(['--no-write-subs'], $this->builder()->build('yt-dlp-cli', ['writesubtitles' => false]));
        $this->assertSame(['--no-match-filters'], $this->builder()->build('yt-dlp-cli', ['no-match-filters' => true]));
        $this->assertSame([], $this->builder()->build('yt-dlp-cli', ['no-match-filters' => false]), 'false keeps the default');
        $this->assertSame(['--no-part', '--zip'], $this->builder()->build('gallery-dl-cli', ['part' => false, 'zip' => true]));
        $this->assertSame([], $this->builder()->build('gallery-dl-cli', ['part' => true]), 'true keeps the default');
    }

    public function testListsRepeatTheFlag(): void
    {
        $this->assertSame(
            ['--match-filters=!is_live', '--match-filters=duration < 600'],
            $this->builder()->build('yt-dlp-cli', ['match_filter' => ['!is_live', 'duration < 600']]),
        );
    }

    public function testHiddenUnknownAndInvalidOptionsAreSkipped(): void
    {
        $this->assertSame(['--limit-rate=1M'], $this->builder()->build('yt-dlp-cli', [
            'ratelimit' => '1M',
            'cookiefile' => '/etc/passwd',
            'proxy' => 'socks5://evil',
            'nope' => true,
            'concurrent_fragment_downloads' => 'many',
            'fixup' => null,
        ]));
        $this->assertSame([], $this->builder()->build('mock', ['ratelimit' => '1M']));
    }

    public function testOptionsAreSkippedWhenTheToolCannotBeInspected(): void
    {
        $dumper = $this->createStub(OptionsDumperInterface::class);
        $dumper->method('dump')->willThrowException(new \RuntimeException('boom'));
        $builder = new OptionsArgumentsBuilder(new OptionsSchemaFactory($dumper, new TagAwareAdapter(new ArrayAdapter()), $this->policy()), new NullLogger());

        $this->assertSame([], $builder->build('yt-dlp-cli', ['ratelimit' => '1M']));
    }

    private function builder(): OptionsArgumentsBuilder
    {
        return new OptionsArgumentsBuilder($this->schemaFactory(), new NullLogger());
    }
}
