<?php

namespace App\Tests\Unit\Service\Downloader\Options;

use App\Service\Downloader\Options\OptionDefinition;
use App\Service\Downloader\Options\OptionsPolicy;
use PHPUnit\Framework\TestCase;

class OptionsPolicyTest extends TestCase
{
    use OptionsTestTrait;

    public function testCodePolicyExposesAllowlistedGroupsWithoutDeniedOptions(): void
    {
        $exposed = $this->schemaFactory()->getExposedOptions('yt-dlp-cli');

        $this->assertArrayHasKey('ratelimit', $exposed, 'Download Options group');
        $this->assertArrayHasKey('writesubtitles', $exposed, 'Subtitle Options group');
        $this->assertArrayNotHasKey('listformats', $exposed, 'soft denied');
        $this->assertArrayNotHasKey('updatetime', $exposed, 'Filesystem Options group is not allowlisted');
    }

    public function testHardDeniedFlagsAndMetavarsAreNeverExposed(): void
    {
        $exposed = $this->schemaFactory()->getExposedOptions('yt-dlp-cli');
        $this->assertArrayNotHasKey('proxy', $exposed, 'hard denied flag');
        $this->assertArrayNotHasKey('cookiefile', $exposed, 'hard denied flag');
        $this->assertArrayNotHasKey('download_archive', $exposed, 'FILE metavar');

        $galleryDl = $this->schemaFactory()->getExposedOptions('gallery-dl-cli');
        $this->assertArrayHasKey('rate', $galleryDl);
        $this->assertArrayHasKey('zip', $galleryDl);
        $this->assertArrayNotHasKey('directory', $galleryDl);
        $this->assertArrayNotHasKey('file-filter', $galleryDl, 'evaluates Python expressions');
    }

    public function testHardDenyChecksEveryFlagAndTheMetavar(): void
    {
        $policy = $this->policy();
        $option = static fn (array $flags, ?string $metavar = null) => new OptionDefinition('key', OptionDefinition::KIND_VALUE, 'string', $flags[0], null, $flags, 'Group', 'Key', metavar: $metavar);

        $this->assertTrue($policy->isHardDenied('gallery-dl-cli', $option(['--option', '-o'])));
        $this->assertTrue($policy->isHardDenied('gallery-dl-cli', $option(['--something', '-o'])), 'short flag');
        $this->assertFalse($policy->isHardDenied('yt-dlp-cli', $option(['--something', '-O'])));
        foreach (['FILE', 'PATH', 'DIR', 'CERTFILE', 'EXPR', 'NETRC_CMD', '[EVENT:]FILE'] as $metavar) {
            $this->assertTrue($policy->isHardDenied('yt-dlp-cli', $option(['--something'], $metavar)), $metavar);
        }
        $this->assertFalse($policy->isHardDenied('yt-dlp-cli', $option(['--something'], 'PROFILE')));
    }

    public function testEveryPlannedHardDeniedFlagIsListed(): void
    {
        foreach (['--exec', '--output', '--paths', '--batch-file', '--config-locations', '--proxy', '--external-downloader', '--external-downloader-args', '--print-to-file', '--cookies'] as $flag) {
            $this->assertContains($flag, OptionsPolicy::HARD_DENY['yt-dlp-cli'], $flag);
        }
        foreach (['-o', '--option', '--config', '--exec', '--input-file', '--cookies'] as $flag) {
            $this->assertContains($flag, OptionsPolicy::HARD_DENY['gallery-dl-cli'], $flag);
        }
    }

    public function testAdminOverridesExposeAndHideOptions(): void
    {
        $this->override('yt-dlp-cli', 'updatetime', true, 'Keep modification time');
        $this->override('yt-dlp-cli', 'ratelimit', false);

        $exposed = $this->schemaFactory()->getExposedOptions('yt-dlp-cli');

        $this->assertSame('Keep modification time', $exposed['updatetime']->title);
        $this->assertArrayNotHasKey('ratelimit', $exposed);
        $this->assertArrayHasKey('rate', $this->schemaFactory()->getExposedOptions('gallery-dl-cli'), 'other downloaders are not affected');
    }

    public function testAdminOverridesCannotExposeHardDeniedOptions(): void
    {
        $this->override('yt-dlp-cli', 'cookiefile', true);
        $this->override('yt-dlp-cli', 'download_archive', true);
        $this->override('gallery-dl-cli', 'file-filter', true);

        $this->assertArrayNotHasKey('cookiefile', $this->schemaFactory()->getExposedOptions('yt-dlp-cli'));
        $this->assertArrayNotHasKey('download_archive', $this->schemaFactory()->getExposedOptions('yt-dlp-cli'));
        $this->assertArrayNotHasKey('file-filter', $this->schemaFactory()->getExposedOptions('gallery-dl-cli'));
    }

    public function testTypeOverrideChangesValueOptionsOnly(): void
    {
        $this->override('gallery-dl-cli', 'sleep', true, type: 'number');
        $this->override('gallery-dl-cli', 'part', true, type: 'string');

        $exposed = $this->schemaFactory()->getExposedOptions('gallery-dl-cli');

        $this->assertSame('number', $exposed['sleep']->type);
        $this->assertSame('boolean', $exposed['part']->type);
    }
}
