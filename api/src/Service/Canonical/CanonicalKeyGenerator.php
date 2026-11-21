<?php

namespace App\Service\Canonical;

use App\Entity\Site;
use App\Model\CanonicalExtractorMatch;
use App\Service\Site\ExtractorMatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Derives the key identifying the media behind a URL, so different spellings of the same media
 * are detected as duplicates (DownloadJob::$canonicalKey). Layers, first match wins:
 *
 * 1. extractor identity (unless the profile disables it): "ytdlp:<IE>:<id>", else "gdl:<category>:<subcategory>:<groups>"
 * 2. the URL normalized by the site's profile, or the default profile: "url:<normalized URL>"
 *
 * Never fails: when the extractor helper fails, the URL key is used.
 */
class CanonicalKeyGenerator
{
    public const string CACHE_TAG = 'canonical_keys';
    /** DownloadJob.canonicalKey column length; longer keys are hashed */
    public const int MAX_LENGTH = 255;
    private const int CACHE_TTL = 3600;

    public function __construct(
        private readonly ExtractorMatcherInterface $extractorMatcher,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly CanonicalProfileRegistry $profileRegistry,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param CanonicalExtractorMatch|null $extractorMatch the extractor identity, if already known (batch lookups);
     *                                                     looked up (and cached per URL) otherwise
     */
    public function generate(string $url, ?Site $site = null, ?CanonicalExtractorMatch $extractorMatch = null): CanonicalKey
    {
        $profile = $this->profileRegistry->forSite($site);

        $key = null;
        if ($profile->useExtractorId) {
            $key = $this->extractorKey($extractorMatch ?? $this->lookup($url), $profile);
        }
        $key ??= new CanonicalKey('url:'.$this->urlNormalizer->normalize($url, $profile), CanonicalKey::LAYER_URL, $profile->name);

        if (strlen($key->key) > self::MAX_LENGTH) {
            $key = new CanonicalKey($key->layer.':sha256:'.hash('sha256', $key->key), $key->layer, $key->profile);
        }

        return $key;
    }

    private function extractorKey(?CanonicalExtractorMatch $match, CanonicalProfile $profile): ?CanonicalKey
    {
        if (null === $match) {
            return null;
        }
        if ($match->hasYtDlp()) {
            return new CanonicalKey(sprintf('ytdlp:%s:%s', $match->ytDlpExtractor, $match->ytDlpId), CanonicalKey::LAYER_YT_DLP, $profile->name);
        }
        if ($match->hasGalleryDl()) {
            $groups = $profile->selectGroups((string) $match->galleryDlSubcategory, $match->galleryDlGroups);

            return new CanonicalKey(
                implode(':', ['gdl', $match->galleryDlCategory, $match->galleryDlSubcategory, ...$groups]),
                CanonicalKey::LAYER_GALLERY_DL,
                $profile->name,
            );
        }

        return null;
    }

    private function lookup(string $url): ?CanonicalExtractorMatch
    {
        return $this->cache->get('canonical_match.'.sha1($url), function (ItemInterface $item, bool &$save) use ($url): ?CanonicalExtractorMatch {
            $item->expiresAfter(self::CACHE_TTL);
            $item->tag(self::CACHE_TAG);

            try {
                return $this->extractorMatcher->canonical($url);
            } catch (\Throwable $e) {
                // Don't cache failures: the next submission retries the helper
                $save = false;
                $this->logger->warning('Unable to derive the extractor identity of a URL, using the URL key', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'exception' => $e,
                ]);

                return null;
            }
        });
    }
}
