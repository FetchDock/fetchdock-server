<?php

namespace App\Service\Site;

use App\Entity\Site;
use App\Entity\SiteExtractor;
use App\Model\ExtractorMatch;
use App\Repository\SiteExtractorRepository;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Resolves a URL to the site it belongs to:
 * host → cache → known site domain → extractor matcher → SiteExtractor mapping → Site.
 *
 * Extractors that aren't mapped yet are grouped into an automatically created site (one per
 * extractor family), and resolved hosts are added to the site's domains.
 *
 * When the offline matcher finds nothing, callers may opt in to the network probe (yt-dlp's generic
 * extractor). Probing fetches the page from the server: only rate-limited endpoints should opt in.
 */
class SiteResolver
{
    public const string CACHE_TAG = 'site_resolve';
    private const int HOST_TTL = 86400;
    private const int UNSUPPORTED_URL_TTL = 3600;
    /**
     * Failed probes are retried sooner than offline misses (the page may have been temporarily
     * unreachable), but are still cached: re-probing on every request would let clients make the
     * server fetch arbitrary pages at the rate limiter's pace.
     */
    private const int FAILED_PROBE_TTL = 300;

    public function __construct(
        private readonly ExtractorMatcherInterface $extractorMatcher,
        private readonly SiteRepository $siteRepository,
        private readonly SiteExtractorRepository $siteExtractorRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return null;
        }

        return rtrim(strtolower($host), '.');
    }

    /**
     * @param bool $probe whether to fall back to the network probe when no extractor matches offline
     */
    public function resolve(string $url, bool $probe = false): ?Site
    {
        $host = self::getHost($url);
        if (null === $host) {
            $this->logger->debug('Cannot resolve site: URL has no host');

            return null;
        }

        $this->logger->debug('Resolving site', ['host' => $host]);

        // A host belongs to one site, whatever the path: cache only positive results per host.
        $slug = $this->cache->get(self::hostCacheKey($host), function (ItemInterface $item, bool &$save) use ($url, $host, $probe): ?string {
            $this->logger->debug('Site host cache miss', ['host' => $host]);
            $item->expiresAfter(self::HOST_TTL);
            $item->tag(self::CACHE_TAG);
            $slug = $this->resolveUncached($url, $host, $probe)?->getSlug();
            $save = null !== $slug;

            $this->logger->debug('Site host cache lookup completed', ['host' => $host, 'slug' => $slug, 'cached' => $save]);

            return $slug;
        });

        $this->logger->debug('Site host cache returned', ['host' => $host, 'slug' => $slug]);
        $site = null !== $slug ? $this->siteRepository->findOneBySlug($slug) : null;
        if (null === $site && null !== $slug) {
            // The site was removed since it was cached
            $this->logger->debug('Cached site no longer exists; resolving again', ['host' => $host, 'slug' => $slug]);
            $this->cache->delete(self::hostCacheKey($host));
            $site = $this->resolveUncached($url, $host, $probe);
        }

        $this->logger->debug('Site resolution completed', [
            'host' => $host,
            'slug' => $site?->getSlug(),
            'enabled' => $site?->isEnabled(),
        ]);

        return $site?->isEnabled() ? $site : null;
    }

    private function resolveUncached(string $url, string $host, bool $probe): ?Site
    {
        $site = $this->siteRepository->findOneByDomain($host);
        if (null !== $site) {
            $this->logger->debug('Site found by domain', ['host' => $host, 'slug' => $site->getSlug()]);

            return $site;
        }

        $this->logger->debug('No site found by domain; checking extractors', ['host' => $host]);
        // Unsupported URLs on a host may still have supported paths: cache them per URL, briefly.
        $matches = $this->cache->get(self::urlCacheKey($url), function (ItemInterface $item) use ($url, $host): array {
            $this->logger->debug('Extractor match cache miss', ['host' => $host]);
            $item->expiresAfter(self::UNSUPPORTED_URL_TTL);
            $item->tag(self::CACHE_TAG);

            return $this->extractorMatcher->match($url);
        });
        $this->logger->debug('Extractor matches returned', ['host' => $host, 'count' => count($matches)]);
        if ([] === $matches && $probe) {
            $matches = $this->cache->get(self::probeCacheKey($url), function (ItemInterface $item) use ($url, $host): array {
                $this->logger->debug('Probe cache miss; probing generic support', ['host' => $host]);
                $matches = $this->extractorMatcher->probe($url);
                $item->expiresAfter([] === $matches ? self::FAILED_PROBE_TTL : self::UNSUPPORTED_URL_TTL);
                $item->tag(self::CACHE_TAG);

                return $matches;
            });
            $this->logger->debug('Probe matches returned', ['host' => $host, 'count' => count($matches)]);
        }
        if ([] === $matches) {
            $this->logger->debug('No extractors matched; site is unsupported', ['host' => $host]);

            return null;
        }

        $site = $this->findOrCreateSite($matches);
        foreach ($matches as $match) {
            if (null === $this->siteExtractorRepository->findOneByDownloaderAndKey($match->downloader, $match->key)) {
                $site->addExtractor(new SiteExtractor($match->downloader, $match->key));
                $this->logger->debug('Added extractor to site', [
                    'slug' => $site->getSlug(),
                    'downloader' => $match->downloader,
                    'key' => $match->key,
                ]);
            }
        }
        $site->addDomain($host);

        $this->entityManager->persist($site);
        $this->entityManager->flush();
        $this->logger->debug('Saved site resolution', ['host' => $host, 'slug' => $site->getSlug()]);

        return $site;
    }

    /**
     * @param non-empty-list<ExtractorMatch> $matches
     */
    private function findOrCreateSite(array $matches): Site
    {
        foreach ($matches as $match) {
            $site = $this->siteExtractorRepository->findOneByDownloaderAndKey($match->downloader, $match->key)?->getSite();
            if (null !== $site) {
                $this->logger->debug('Site found by extractor', [
                    'slug' => $site->getSlug(),
                    'downloader' => $match->downloader,
                    'key' => $match->key,
                ]);

                return $site;
            }
        }

        foreach ($matches as $match) {
            $site = $this->siteRepository->findOneBySlug(self::slugify($match->family));
            if (null !== $site) {
                $this->logger->debug('Site found by extractor family', ['slug' => $site->getSlug(), 'family' => $match->family]);

                return $site;
            }
        }

        $family = $matches[0]->family;
        $this->logger->info('Creating site for unmapped extractor family', ['family' => $family]);

        return new Site()
            ->setSlug(self::slugify($family))
            ->setName(ucfirst($family))
            ->setCurated(false)
            ->setEnabled(true);
    }

    public static function slugify(string $value): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');

        return '' === $slug ? 'site-'.substr(sha1($value), 0, 8) : substr($slug, 0, 64);
    }

    private static function hostCacheKey(string $host): string
    {
        return 'site_resolve.'.preg_replace('/[^a-z0-9.\-_]/', '_', $host);
    }

    private static function urlCacheKey(string $url): string
    {
        return 'site_resolve_url.'.sha1($url);
    }

    private static function probeCacheKey(string $url): string
    {
        return 'site_resolve_probe.'.sha1($url);
    }
}
