<?php

namespace App\Tests\Unit\Service\Downloader\Options;

use App\Service\Downloader\Options\OptionsValidator;
use PHPUnit\Framework\TestCase;

class OptionsValidatorTest extends TestCase
{
    use OptionsTestTrait;

    public function testValidOptions(): void
    {
        $this->assertSame([], $this->validator()->validate('yt-dlp-cli', [
            'ratelimit' => '2M',
            'concurrent_fragment_downloads' => 4,
            'sleep_interval' => 1.5,
            'writesubtitles' => false,
            'match_filter' => ['!is_live', 'duration < 600'],
            'fixup' => 'warn',
            'no-match-filters' => true,
        ]));
        $this->assertSame([], $this->validator()->validate('mock', []));
    }

    public function testUnknownAndHiddenOptionsAreRejected(): void
    {
        $violations = $this->validator()->validate('yt-dlp-cli', ['nope' => true, 'cookiefile' => '/etc/passwd', 'listformats' => true]);

        $this->assertSame(['nope', 'cookiefile', 'listformats'], array_keys($violations));
        $this->assertStringContainsString('not configurable', $violations['cookiefile']);
        $this->assertArrayHasKey('ratelimit', $this->validator()->validate('mock', ['ratelimit' => '1M']));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'string for boolean' => ['writesubtitles', 'yes'];
        yield 'string for integer' => ['concurrent_fragment_downloads', '4'];
        yield 'float for integer' => ['concurrent_fragment_downloads', 1.5];
        yield 'integer for string' => ['ratelimit', 2];
        yield 'not a choice' => ['fixup', 'sometimes'];
        yield 'too long' => ['ratelimit', str_repeat('a', 1025)];
        yield 'newline' => ['ratelimit', "1M\n--exec"];
        yield 'NUL byte' => ['ratelimit', "1M\0"];
        yield 'object for list' => ['match_filter', ['a' => 'b']];
        yield 'list with integer' => ['match_filter', [1]];
        yield 'too many items' => ['match_filter', array_fill(0, 21, 'x')];
        yield 'list for string' => ['ratelimit', ['1M']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $option, mixed $value): void
    {
        $this->assertArrayHasKey($option, $this->validator()->validate('yt-dlp-cli', [$option => $value]));
    }

    public function testNormalizeDropsNullValues(): void
    {
        $this->assertSame(['a' => false], OptionsValidator::normalize(['a' => false, 'b' => null]));
        $this->assertSame([], OptionsValidator::normalize(null));
    }

    private function validator(): OptionsValidator
    {
        return new OptionsValidator($this->schemaFactory());
    }
}
