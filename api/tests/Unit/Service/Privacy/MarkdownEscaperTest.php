<?php

namespace App\Tests\Unit\Service\Privacy;

use App\Service\Privacy\MarkdownEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownEscaperTest extends TestCase
{
    public static function texts(): iterable
    {
        yield 'plain' => ['Acme Ltd.', 'Acme Ltd.'];
        yield 'emphasis and links' => ['*Acme* [Ltd](javascript:alert(1))', '\*Acme\* \[Ltd\](javascript:alert(1))'];
        yield 'html' => ['<script>x</script> & co', '\<script\>x\</script\> \& co'];
        yield 'line breaks' => ["a\n\n# b\n- c", 'a \# b - c'];
        yield 'list marker' => ['- item', '\- item'];
        yield 'heading' => ['# Title', '\# Title'];
        yield 'ordered list marker' => ['1. first', '1\. first'];
        yield 'thematic break' => ['---', '\\---'];
        yield 'version numbers' => ['0.9.0', '0.9.0'];
        yield 'hyphenated' => ['-foo', '-foo'];
        yield 'separators keep their spaces' => ['; ', '; '];
    }

    #[DataProvider('texts')]
    public function testText(string $text, string $expected): void
    {
        self::assertSame($expected, MarkdownEscaper::text($text));
    }

    public function testUrl(): void
    {
        self::assertSame('https://example.org/a%3Cb%3E?q=1', MarkdownEscaper::url("https://example.org/a<b> ?q=1\n"));
        self::assertSame('https://example.org/%5C', MarkdownEscaper::url('https://example.org/\\'));
    }
}
