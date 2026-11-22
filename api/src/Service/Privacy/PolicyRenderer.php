<?php

namespace App\Service\Privacy;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Intl\Countries;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;

/**
 * Renders the privacy policy (ADR 0013): templates/privacy/policy.md.twig → Markdown → HTML.
 *
 * The template autoescapes with the "markdown" strategy (MarkdownEscaper::text()), so answers stay literal text;
 * link destinations use "markdown_url". The operator's additional text is Markdown by design. The HTML step uses the
 * core CommonMark parser only, escapes raw HTML and drops unsafe links, so no answer can inject markup or scripts.
 */
class PolicyRenderer
{
    public const string TEMPLATE = 'privacy/policy.md.twig';

    private readonly CommonMarkConverter $converter;

    public function __construct(
        private readonly Environment $twig,
    ) {
        $escaper = $this->twig->getRuntime(EscaperRuntime::class);
        $escaper->setEscaper('markdown', static fn (string $string): string => MarkdownEscaper::text($string));
        $escaper->setEscaper('markdown_url', static fn (string $string): string => MarkdownEscaper::url($string));

        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /**
     * @param int|null $revision the revision number the text is for, null for a preview
     */
    public function render(PolicyInputs $inputs, ?int $revision): RenderedPolicy
    {
        $answers = $inputs->answers->toArray();
        if (\is_string($answers['hosting']['country'] ?? null)) {
            $answers['hosting']['country'] = self::countryName($answers['hosting']['country']);
        }
        foreach ($answers['processors'] ?? [] as $index => $processor) {
            if (\is_string($processor['country'] ?? null)) {
                $answers['processors'][$index]['country'] = self::countryName($processor['country']);
            }
        }

        $markdown = $this->twig->render(self::TEMPLATE, [
            'revision' => $revision,
            'textVersion' => $inputs->textVersion,
            'appVersion' => $inputs->appVersion,
            'answers' => $answers,
            'config' => $inputs->configuration,
            'inventory' => $inputs->inventory,
        ]);
        // Collapse the blank lines left by template control structures
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown))."\n";

        return new RenderedPolicy($markdown, $this->toHtml($markdown));
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }

    private static function countryName(string $code): string
    {
        return Countries::exists($code) ? Countries::getName($code, 'en') : $code;
    }
}
