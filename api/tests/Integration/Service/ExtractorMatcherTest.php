<?php

namespace App\Tests\Integration\Service;

use App\Model\ExtractorMatch;
use App\Service\Site\ExtractorMatcher;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
}
