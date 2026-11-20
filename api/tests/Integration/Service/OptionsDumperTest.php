<?php

namespace App\Tests\Integration\Service;

use App\Repository\DownloaderOptionPolicyRepository;
use App\Service\Downloader\Options\OptionsDumper;
use App\Service\Downloader\Options\OptionsPolicy;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Runs the real bin/dump_options.py against the gallery-dl and yt-dlp installed in the container,
 * with the real code policy (no admin overrides).
 */
class OptionsDumperTest extends KernelTestCase
{
    private OptionsDumper $dumper;
    private OptionsSchemaFactory $schemaFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->dumper = new OptionsDumper(new NullLogger(), $container->getParameter('kernel.project_dir').'/bin/dump_options.py');

        $overrides = $this->createStub(DownloaderOptionPolicyRepository::class);
        $overrides->method('findByDownloaderIndexedByOption')->willReturn([]);
        $this->schemaFactory = new OptionsSchemaFactory(
            $this->dumper,
            new TagAwareAdapter(new ArrayAdapter()),
            new OptionsPolicy($container->getParameter('app.downloader_options_policy'), $overrides),
        );
    }

    public function testDumpsBothTools(): void
    {
        foreach (OptionsSchemaFactory::TOOLS as $tool) {
            $dump = $this->dumper->dump($tool);

            $this->assertNotSame('', $dump['version'], $tool);
            $this->assertGreaterThan(50, count($dump['options']), $tool);
            foreach ($dump['options'] as $option) {
                $this->assertContains($option['kind'], ['value', 'append', 'true', 'false', 'const', 'other'], $tool);
                $this->assertNotEmpty($option['flags'], $tool);
            }
        }
    }

    public function testUnknownToolFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->dumper->dump('nope');
    }

    public function testSchemasExposeCommonOptionsButNoHardDeniedOnes(): void
    {
        $ytDlp = $this->schemaFactory->getSchema('yt-dlp-cli')['properties'];
        $this->assertSame('--limit-rate', $ytDlp['ratelimit']['x-flag']);
        $this->assertSame('--write-subs', $ytDlp['writesubtitles']['x-flag']);
        $this->assertSame('--no-write-subs', $ytDlp['writesubtitles']['x-flag-false']);
        $this->assertArrayHasKey('format', $ytDlp);

        $galleryDl = $this->schemaFactory->getSchema('gallery-dl-cli')['properties'];
        $this->assertSame('--limit-rate', $galleryDl['rate']['x-flag']);
        $this->assertArrayHasKey('sleep', $galleryDl);

        foreach (['yt-dlp-cli' => $ytDlp, 'gallery-dl-cli' => $galleryDl] as $downloader => $properties) {
            $all = $this->schemaFactory->getAllOptions($downloader);
            foreach (array_keys($properties) as $key) {
                $this->assertNotEmpty($all[$key]->flags);
                $this->assertSame([], array_intersect($all[$key]->flags, OptionsPolicy::HARD_DENY[$downloader]), $downloader.' '.$key);
                $this->assertDoesNotMatchRegularExpression('/FILE|PATH|DIR|EXPR|CMD/', (string) $all[$key]->metavar, $downloader.' '.$key);
            }
            foreach (OptionsPolicy::HARD_DENY[$downloader] as $flag) {
                $this->assertNotContains($flag, array_column($properties, 'x-flag'), $downloader.' '.$flag);
                $this->assertNotContains($flag, array_column($properties, 'x-flag-false'), $downloader.' '.$flag);
            }
        }
    }
}
