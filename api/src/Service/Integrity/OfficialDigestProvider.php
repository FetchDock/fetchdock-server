<?php

namespace App\Service\Integrity;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The manifest digest CI published for an app version (ADR 0013): <version>.sha256 on the orphan "integrity-data"
 * branch of fetchdock-server. Every official build publishes one.
 *
 * A fetched digest is cached for the version and the local manifest digest, so an instance asks GitHub once per
 * image. Failures aren't cached: the next check asks again.
 */
class OfficialDigestProvider
{
    public const string DIGEST_URL = 'https://raw.githubusercontent.com/FetchDock/fetchdock-server/integrity-data/%s.sha256';

    /** Semantic versions only: "dev" and "undefined" (local builds) have no published digest. */
    private const string VERSION_PATTERN = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The official digest for the version, or null when there is none or it can't be fetched.
     */
    public function fetch(string $appVersion, string $manifestDigest): ?string
    {
        if (!preg_match(self::VERSION_PATTERN, $appVersion)) {
            return null;
        }

        $item = $this->cache->getItem('integrity_official_digest.'.hash('xxh128', $appVersion."\0".$manifestDigest));
        if ($item->isHit()) {
            return (string) $item->get();
        }

        $url = \sprintf(self::DIGEST_URL, rawurlencode($appVersion));
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_duration' => 30]);
            if (200 !== $response->getStatusCode()) {
                $this->logger->info('No official integrity digest for this version', ['version' => $appVersion, 'status' => $response->getStatusCode()]);

                return null;
            }
            $digest = self::parse($response->getContent());
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Cannot fetch the official integrity digest', ['version' => $appVersion, 'error' => $e->getMessage()]);

            return null;
        }

        if (null === $digest) {
            $this->logger->warning('Invalid official integrity digest', ['version' => $appVersion]);

            return null;
        }

        $this->cache->save($item->set($digest));

        return $digest;
    }

    /**
     * The digest of a sha256sum line ("<hex>  integrity-manifest.json") or of a bare hex digest.
     */
    public static function parse(string $content): ?string
    {
        return preg_match('/^\s*([0-9a-f]{64})(\s|$)/i', $content, $matches) ? strtolower($matches[1]) : null;
    }
}
