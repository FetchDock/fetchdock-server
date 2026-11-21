<?php

namespace App\Tests\Double;

use App\Service\Canonical\CanonicalKeyGenerator;
use App\Service\Canonical\CanonicalProfileRegistry;
use App\Service\Canonical\UrlNormalizer;
use App\Service\Site\ExtractorMatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Yaml\Yaml;

/**
 * Canonical key generators for unit tests, with the code profiles of config/packages/url_canonicalization.yaml.
 */
final class CanonicalKeyGenerators
{
    public static function profileRegistry(): CanonicalProfileRegistry
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/url_canonicalization.yaml');

        return new CanonicalProfileRegistry($config['parameters']['app.url_canonicalization.profiles']);
    }

    public static function create(?ExtractorMatcherInterface $matcher = null, ?LoggerInterface $logger = null): CanonicalKeyGenerator
    {
        return new CanonicalKeyGenerator(
            $matcher ?? new FakeExtractorMatcher(),
            new UrlNormalizer(),
            self::profileRegistry(),
            new TagAwareAdapter(new ArrayAdapter()),
            $logger ?? new NullLogger(),
        );
    }
}
