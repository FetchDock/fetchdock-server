<?php

namespace App\Service\Privacy;

/**
 * Escapes plain text for the policy's Markdown template (the Twig "markdown" escaper, see PolicyRenderer), so an
 * answer like "*Acme* [Ltd](javascript:...)" stays literal text. The HTML renderer escapes raw HTML on top of this.
 */
final class MarkdownEscaper
{
    /**
     * Inline text: whitespace runs (including line breaks) collapsed to single spaces, Markdown punctuation
     * backslash-escaped, and block markers at the start (list items, headings, thematic breaks) neutralized.
     */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/([\\\\`*_\[\]<>#!|~&])/', '\\\\$1', $text);
        // List items need a space after the marker; thematic breaks and setext underlines repeat it
        $text = (string) preg_replace('/^([-+=])(?=[\s\-=]|$)/', '\\\\$1', $text);

        return (string) preg_replace('/^(\d+)([.)])(?=\s|$)/', '$1\\\\$2', $text);
    }

    /**
     * A link destination, for use inside <...>: no whitespace or control characters, and no unescaped angle
     * brackets. Unsafe schemes (javascript:, data:, ...) are dropped by the HTML renderer.
     */
    public static function url(string $url): string
    {
        $url = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $url);

        return strtr($url, ['\\' => '%5C', '<' => '%3C', '>' => '%3E']);
    }
}
