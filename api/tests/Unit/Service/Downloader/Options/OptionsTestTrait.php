<?php

namespace App\Tests\Unit\Service\Downloader\Options;

use App\Entity\DownloaderOptionPolicy;
use App\Repository\DownloaderOptionPolicyRepository;
use App\Service\Downloader\Options\OptionsPolicy;
use App\Service\Downloader\Options\OptionsSchemaFactory;
use App\Tests\Double\FakeOptionsDumper;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Yaml\Yaml;

/**
 * Options services backed by the fixture dumps (tests/Double/options) and the real code policy.
 */
trait OptionsTestTrait
{
    /** @var list<DownloaderOptionPolicy> */
    private array $policyOverrides = [];

    private function policy(): OptionsPolicy
    {
        $config = Yaml::parseFile(dirname(__DIR__, 5).'/config/packages/downloader_policy.yaml')['parameters']['app.downloader_options_policy'];

        $repository = $this->createStub(DownloaderOptionPolicyRepository::class);
        $repository->method('findByDownloaderIndexedByOption')->willReturnCallback(function (string $downloader) {
            $overrides = [];
            foreach ($this->policyOverrides as $override) {
                if ($override->getDownloader() === $downloader) {
                    $overrides[$override->getOption()] = $override;
                }
            }

            return $overrides;
        });

        return new OptionsPolicy($config, $repository);
    }

    private function schemaFactory(): OptionsSchemaFactory
    {
        return new OptionsSchemaFactory(new FakeOptionsDumper(), new TagAwareAdapter(new ArrayAdapter()), $this->policy());
    }

    private function override(string $downloader, string $option, bool $exposed, ?string $label = null, ?string $type = null): void
    {
        $this->policyOverrides[] = new DownloaderOptionPolicy()->setDownloader($downloader)->setOption($option)->setExposed($exposed)->setLabel($label)->setType($type);
    }
}
