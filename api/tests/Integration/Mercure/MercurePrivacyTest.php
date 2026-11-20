<?php

namespace App\Tests\Integration\Mercure;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Entity\DownloadJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * Guards that every Mercure publication is private (see GHSA-m2vr-gj6r-hf63 and #74).
 *
 * The hub doesn't accept anonymous subscribers, but a non-private update would still reach any subscriber whose token
 * covers its topic. Making something public must be a conscious decision: add it to an allow-list below, with a reason.
 */
class MercurePrivacyTest extends KernelTestCase
{
    /**
     * API resources whose updates are intentionally public: resource class => reason. Keep this empty if possible.
     *
     * @var array<class-string, string>
     */
    private const array PUBLIC_RESOURCES = [];

    /**
     * API resources whose "mercure" option is an expression, which can't be checked statically: resource class => the
     * test that proves the evaluated options are private.
     *
     * @var array<class-string, string>
     */
    private const array EXPRESSION_RESOURCES = [
        DownloadJob::class => 'App\Tests\Unit\Mercure\UserTopicsTest::testDownloadJobMercureOptionsArePrivateToTheOwner',
    ];

    public function testApiResourceUpdatesArePrivate(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $resourceNames = $container->get(ResourceNameCollectionFactoryInterface::class)->create();
        $metadataFactory = $container->get(ResourceMetadataCollectionFactoryInterface::class);

        $checked = 0;
        foreach ($resourceNames as $resourceClass) {
            foreach ($metadataFactory->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    $mercure = $operation->getMercure();
                    if (null === $mercure || false === $mercure) {
                        continue;
                    }
                    ++$checked;
                    $where = sprintf('%s (operation %s)', $resourceClass, $operation->getName());

                    if (\is_string($mercure)) {
                        $this->assertArrayHasKey($resourceClass, self::EXPRESSION_RESOURCES, "{$where} uses a Mercure expression; add it to EXPRESSION_RESOURCES with a test proving it's private.");
                        continue;
                    }
                    if (\array_key_exists($resourceClass, self::PUBLIC_RESOURCES)) {
                        continue;
                    }

                    $this->assertIsArray($mercure, "{$where} publishes public Mercure updates (\"mercure: true\"). Use ['private' => true, ...], or add it to PUBLIC_RESOURCES with a reason.");
                    $this->assertTrue($mercure['private'] ?? false, "{$where} publishes Mercure updates without 'private' => true.");
                }
            }
        }

        // Fail loudly if the metadata lookup stops finding the resources we know publish updates
        $this->assertGreaterThan(0, $checked, 'No API resources with Mercure updates found; is the metadata lookup still working?');
    }

    public function testCustomUpdatesArePrivate(): void
    {
        $files = new Finder()->files()->in(dirname(__DIR__, 3).'/src')->name('*.php')->contains('new Update(');

        $calls = 0;
        foreach ($files as $file) {
            $source = $file->getContents();
            $offset = 0;
            while (false !== ($start = strpos($source, 'new Update(', $offset))) {
                $call = $this->extractCall($source, $start + strlen('new Update'));
                $line = substr_count(substr($source, 0, $start), "\n") + 1;
                $this->assertMatchesRegularExpression('/\bprivate:\s*true\b/', $call, sprintf('%s:%d creates a Mercure Update without "private: true".', $file->getRelativePathname(), $line));
                ++$calls;
                $offset = $start + 1;
            }
        }

        $this->assertGreaterThan(0, $calls, 'No custom Mercure updates found; is the source scan still working?');
    }

    /**
     * Returns the argument list of a call, from the opening parenthesis at $openParen to its matching closing one.
     */
    private function extractCall(string $source, int $openParen): string
    {
        $depth = 0;
        for ($i = $openParen, $length = strlen($source); $i < $length; ++$i) {
            if ('(' === $source[$i]) {
                ++$depth;
            } elseif (')' === $source[$i] && 0 === --$depth) {
                return substr($source, $openParen, $i - $openParen + 1);
            }
        }

        $this->fail('Unbalanced parentheses in a "new Update(" call.');
    }
}
