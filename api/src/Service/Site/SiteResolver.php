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
 */
class SiteResolver
{
    public const string CACHE_TAG = 'site_resolve';
    private const int HOST_TTL = 86400;
    private const int UNSUPPORTED_URL_TTL = 3600;

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

    public function resolve(string $url): ?Site
    {
        $host = self::getHost($url);
        if (null === $host) {
            return null;
        }

        // A host belongs to one site, whatever the path: cache only positive results per host.
        $slug = $this->cache->get(self::hostCacheKey($host), function (ItemInterface $item, bool &$save) use ($url, $host): ?string {
            $item->expiresAfter(self::HOST_TTL);
            $item->tag(self::CACHE_TAG);
            $slug = $this->resolveUncached($url, $host)?->getSlug();
            $save = null !== $slug;

            return $slug;
        });

        $site = null !== $slug ? $this->siteRepository->findOneBySlug($slug) : null;
        if (null === $site && null !== $slug) {
            // The site was removed since it was cached
            $this->cache->delete(self::hostCacheKey($host));
            $site = $this->resolveUncached($url, $host);
        }

        return $site?->isEnabled() ? $site : null;
    }

    private function resolveUncached(string $url, string $host): ?Site
    {
        $site = $this->siteRepository->findOneByDomain($host);
        if (null !== $site) {
            return $site;
        }

        // Unsupported URLs on a host may still have supported paths: cache them per URL, briefly.
        $matches = $this->cache->get(self::urlCacheKey($url), function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(self::UNSUPPORTED_URL_TTL);
            $item->tag(self::CACHE_TAG);

            return $this->extractorMatcher->match($url);
        });
        if ([] === $matches) {
            return null;
        }

        $site = $this->findOrCreateSite($matches);
        foreach ($matches as $match) {
            if (null === $this->siteExtractorRepository->findOneByDownloaderAndKey($match->downloader, $match->key)) {
                $site->addExtractor(new SiteExtractor($match->downloader, $match->key));
            }
        }
        $site->addDomain($host);

        $this->entityManager->persist($site);
        $this->entityManager->flush();

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
                return $site;
            }
        }

        foreach ($matches as $match) {
            $site = $this->siteRepository->findOneBySlug(self::slugify($match->family));
            if (null !== $site) {
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
}
