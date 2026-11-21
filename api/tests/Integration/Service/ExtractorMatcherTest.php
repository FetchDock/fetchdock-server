<?php

namespace App\Tests\Integration\Service;

use App\Model\CanonicalExtractorMatch;
use App\Model\ExtractorMatch;
use App\Service\Site\ExtractorMatcher;
use App\Tests\Double\CanonicalKeyGenerators;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the real bin/resolve_url.py against the gallery-dl and yt-dlp installed in the container.
 */
class ExtractorMatcherTest extends KernelTestCase
{
    private ExtractorMatcher $matcher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->matcher = new ExtractorMatcher(new NullLogger(), self::getContainer()->getParameter('kernel.project_dir').'/bin/resolve_url.py');
    }

    public function testMatchesBothToolsForATweet(): void
    {
        $matches = $this->matcher->match('https://x.com/user/status/1234567890');

        $this->assertContainsEquals(new ExtractorMatch('gallery-dl-cli', 'twitter', 'twitter'), $matches);
        $this->assertContainsEquals(new ExtractorMatch('yt-dlp-cli', 'Twitter', 'twitter'), $matches);
    }

    public function testYtDlpFamilyIsTheExtractorModule(): void
    {
        $matches = $this->matcher->match('https://youtu.be/dQw4w9WgXcQ');

        $this->assertContainsEquals(new ExtractorMatch('yt-dlp-cli', 'Youtube', 'youtube'), $matches);
    }

    public function testGenericUrlsDoNotMatch(): void
    {
        $this->assertSame([], $this->matcher->match('https://example.com/some/page'));
    }

    public function testRegionalSubdomainMatchesThroughItsParentDomain(): void
    {
        // yt-dlp's Vimeo patterns don't allow regional subdomains: only the vimeo.com variant matches
        $this->assertEquals(
            [new ExtractorMatch('yt-dlp-cli', 'Vimeo', 'vimeo')],
            $this->matcher->match('https://fr.vimeo.com/76979871'),
        );
    }

    public static function frontPages(): iterable
    {
        yield 'front page' => ['https://vimeo.com/'];
        yield 'regional front page' => ['https://fr.vimeo.com/'];
    }

    #[DataProvider('frontPages')]
    public function testFrontPageMatchesTheExtractorsKnownForItsHost(string $url): void
    {
        $matches = $this->matcher->match($url);

        $this->assertContainsEquals(new ExtractorMatch('yt-dlp-cli', 'Vimeo', 'vimeo'), $matches);
        $this->assertSame(['vimeo'], array_values(array_unique(array_map(static fn (ExtractorMatch $m): string => $m->family, $matches))));
    }

    public function testFrontPageOfAnUnknownHostDoesNotMatch(): void
    {
        $this->assertSame([], $this->matcher->match('https://example.com/'));
    }

    public static function unprobeableUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/', 'host resolves to a non-public address'];
        yield 'localhost' => ['http://localhost:8080/', 'host resolves to a non-public address'];
        yield 'private network' => ['http://10.0.0.1/', 'host resolves to a non-public address'];
        yield 'link-local (cloud metadata)' => ['http://169.254.169.254/latest/meta-data/', 'host resolves to a non-public address'];
        yield 'IPv6 loopback' => ['http://[::1]/', 'host resolves to a non-public address'];
        yield 'compose service' => ['http://database:5432/', 'host resolves to a non-public address'];
        yield 'file scheme' => ['file:///etc/passwd', 'unsupported scheme'];
    }

    #[DataProvider('unprobeableUrls')]
    public function testProbeRefusesNonPublicTargets(string $url, string $reason): void
    {
        $process = new Process(['python3', self::getContainer()->getParameter('kernel.project_dir').'/bin/resolve_url.py', 'probe', $url]);
        $process->mustRun();

        $this->assertSame(['extractors' => []], json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('Refusing to probe: '.$reason, $process->getErrorOutput());
        $this->assertStringNotContainsString('yt-dlp:', $process->getErrorOutput());
    }

    public function testCatalogListsExtractorsWithHosts(): void
    {
        $catalog = $this->matcher->catalog(['twitter']);

        $this->assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            $this->assertSame('twitter', $entry['match']->family);
        }
        $hosts = array_merge(...array_column($catalog, 'hosts'));
        $this->assertContains('x.com', $hosts);
    }

    public function testUrlIsPassedAsArgumentNotThroughAShell(): void
    {
        $marker = sys_get_temp_dir().'/resolve_url_injection_'.bin2hex(random_bytes(4));

        $this->assertSame([], $this->matcher->match('https://example.com/$(touch '.$marker.')`touch '.$marker.'`'));
        $this->assertFileDoesNotExist($marker);
    }

    public static function scriptCommands(): iterable
    {
        yield 'resolve' => ['resolve', 'https://x.com/user/status/1234567890?token=secret', 'Resolution completed'];
        yield 'catalog' => ['catalog', '["twitter"]', 'Catalog completed'];
    }

    #[DataProvider('scriptCommands')]
    public function testScriptWritesJsonToStdoutAndDebugLogsToStderr(string $command, string $argument, string $expectedLog): void
    {
        $process = new Process(['python3', self::getContainer()->getParameter('kernel.project_dir').'/bin/resolve_url.py', $command, $argument]);
        $process->mustRun();

        $output = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('extractors', $output);
        $this->assertStringContainsString($expectedLog, $process->getErrorOutput());
        $this->assertStringNotContainsString('secret', $process->getErrorOutput());
    }

    public function testMatcherForwardsScriptDiagnosticsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            'Extractor matcher diagnostics',
            $this->callback(static fn (array $context): bool => 'resolve' === $context['command']
                && str_contains($context['output'], 'Resolution completed')
                && !str_contains($context['output'], 'secret')),
        );
        $matcher = new ExtractorMatcher($logger, self::getContainer()->getParameter('kernel.project_dir').'/bin/resolve_url.py');

        $this->assertSame([], $matcher->match('https://example.com/private-path?token=secret'));
    }

    public function testScriptLogsUnavailableDependencies(): void
    {
        $process = new Process(['python3', '-S', self::getContainer()->getParameter('kernel.project_dir').'/bin/resolve_url.py', 'resolve', 'https://example.com/']);
        $process->mustRun();

        $this->assertSame(['extractors' => []], json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('gallery-dl is not available', $process->getErrorOutput());
        $this->assertStringContainsString('yt-dlp is not available', $process->getErrorOutput());
    }

    public static function urlSpellings(): iterable
    {
        yield 'share parameter' => ['https://vimeo.com/76979871', 'https://vimeo.com/76979871?share=copy'];
        yield 'regional subdomain and fragment' => ['https://vimeo.com/76979871', 'https://fr.vimeo.com/76979871#t=30'];
        yield 'short link and tracking parameter' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?si=abc'];
    }

    /**
     * #94: the same media behind different URL spellings gets the same canonical key.
     */
    #[DataProvider('urlSpellings')]
    public function testUrlSpellingsOfTheSameMediaShareTheCanonicalKey(string $url, string $variant): void
    {
        $generator = CanonicalKeyGenerators::create($this->matcher);

        $key = $generator->generate($url);
        $this->assertStringStartsWith('ytdlp:', $key->key);
        $this->assertSame($key->key, $generator->generate($variant)->key);
    }

    public function testCanonicalReportsBothTools(): void
    {
        $this->assertEquals(
            new CanonicalExtractorMatch('Twitter', '1234567890', 'twitter', 'tweet', ['user', '1234567890']),
            $this->matcher->canonical('https://x.com/user/status/1234567890?s=20'),
        );
        $this->assertEquals(new CanonicalExtractorMatch('Vimeo', '76979871'), $this->matcher->canonical('https://vimeo.com/76979871?share=copy'));
    }

    public function testCanonicalOfUnknownMediaIsEmpty(): void
    {
        $this->assertEquals(new CanonicalExtractorMatch(), $this->matcher->canonical('https://example.com/some/page'));
    }

    public function testCanonicalBatchKeysResultsByUrl(): void
    {
        $result = $this->matcher->canonicalBatch(['https://vimeo.com/76979871', 'https://example.com/some/page', 'https://vimeo.com/76979871']);

        $this->assertEquals([
            'https://vimeo.com/76979871' => new CanonicalExtractorMatch('Vimeo', '76979871'),
            'https://example.com/some/page' => new CanonicalExtractorMatch(),
        ], $result);
    }
}
