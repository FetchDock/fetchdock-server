<?php

namespace App\Service\Site;

use App\Entity\Site;
use App\Entity\SiteExtractor;
use App\Entity\SiteSetting;
use App\Entity\UserSiteSetting;
use App\Repository\SiteExtractorRepository;
use App\Repository\SiteRepository;
use App\Service\Canonical\CanonicalProfileRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Synchronises the curated sites of config/sites.yaml into the database, and maps all extractors
 * of their families (as known by the installed tools) to them.
 */
class SiteSynchronizer
{
    private const array SETTING_FIELDS = ['sendCookies', 'sendUserAgent', 'sendReferrer', 'downloader'];

    public function __construct(
        private readonly ExtractorMatcherInterface $extractorMatcher,
        private readonly SiteRepository $siteRepository,
        private readonly SiteExtractorRepository $siteExtractorRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly CanonicalProfileRegistry $canonicalProfileRegistry,
        #[Autowire('%kernel.project_dir%/config/sites.yaml')]
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array{sites: int, extractors: int, removedSites: list<string>}
     */
    public function sync(): array
    {
        $definitions = $this->loadDefinitions();

        $families = array_values(array_unique(array_merge(...array_values(array_map(
            static fn (array $definition): array => $definition['families'],
            $definitions,
        )))));
        $catalog = $this->extractorMatcher->catalog($families);

        $extractorCount = 0;
        foreach ($definitions as $slug => $definition) {
            $site = $this->siteRepository->findOneBySlug($slug) ?? new Site()->setSlug($slug);
            $site
                ->setName($definition['name'])
                ->setCurated(true)
                ->setEnabled($definition['enabled'])
                ->setCanonicalization($definition['canonicalization']);

            $domains = [...$site->getDomains(), ...$definition['domains']];
            $keys = $definition['extractors'];
            foreach ($catalog as ['match' => $match, 'hosts' => $hosts]) {
                if (!in_array($match->family, $definition['families'], true)) {
                    continue;
                }
                $keys[$match->downloader][] = $match->key;
                foreach ($hosts as $host) {
                    if (self::isWithinDomains($host, $definition['domains'])) {
                        $domains[] = $host;
                    }
                }
            }
            $site->setDomains($domains);

            foreach ($keys as $downloader => $downloaderKeys) {
                foreach (array_unique($downloaderKeys) as $key) {
                    $site->addExtractor($this->siteExtractorRepository->findOneByDownloaderAndKey($downloader, $key) ?? new SiteExtractor($downloader, $key));
                    ++$extractorCount;
                }
            }

            $this->applyDefaults($site, $definition['defaults']);
            $this->entityManager->persist($site);
        }
        $this->entityManager->flush();

        $removedSites = $this->removeEmptyAutomaticSites();
        $this->entityManager->flush();

        $this->cache->invalidateTags([SiteResolver::CACHE_TAG]);

        return ['sites' => count($definitions), 'extractors' => $extractorCount, 'removedSites' => $removedSites];
    }

    private static function isWithinDomains(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function applyDefaults(Site $site, ?array $defaults): void
    {
        if (null === $defaults) {
            return;
        }

        $setting = $site->getDefaultSetting() ?? new SiteSetting();
        $setting
            ->setSendCookies($defaults['sendCookies'] ?? null)
            ->setSendUserAgent($defaults['sendUserAgent'] ?? null)
            ->setSendReferrer($defaults['sendReferrer'] ?? null)
            ->setDownloader($defaults['downloader'] ?? null);
        $site->setDefaultSetting($setting);
    }

    /**
     * Automatic sites whose extractors all moved to curated sites, and that no user has settings for.
     *
     * @return list<string>
     */
    private function removeEmptyAutomaticSites(): array
    {
        $removed = [];
        foreach ($this->siteRepository->findBy(['curated' => false]) as $site) {
            if (!$site->getExtractors()->isEmpty()) {
                continue;
            }
            if ($this->entityManager->getRepository(UserSiteSetting::class)->count(['site' => $site]) > 0) {
                $this->logger->warning('Automatic site has no extractors left but has user settings, keeping it', ['site' => $site->getSlug()]);
                continue;
            }
            $this->entityManager->remove($site);
            $removed[] = $site->getSlug();
        }

        return $removed;
    }

    /**
     * @return array<string, array{name: string, domains: list<string>, families: list<string>, extractors: array<string, list<string>>, enabled: bool, defaults: ?array, canonicalization: ?array}>
     */
    private function loadDefinitions(): array
    {
        $config = Yaml::parseFile($this->configPath);
        $definitions = [];

        foreach ($config['sites'] ?? [] as $slug => $site) {
            if (!is_string($slug) || SiteResolver::slugify($slug) !== $slug) {
                throw new \InvalidArgumentException(sprintf('Invalid site slug "%s" in %s.', $slug, $this->configPath));
            }
            if (!is_string($site['name'] ?? null) || '' === $site['name']) {
                throw new \InvalidArgumentException(sprintf('Site "%s" needs a name.', $slug));
            }
            $defaults = $site['defaults'] ?? null;
            if (null !== $defaults && ([] !== array_diff(array_keys($defaults), self::SETTING_FIELDS))) {
                throw new \InvalidArgumentException(sprintf('Site "%s" has unknown defaults; allowed: %s.', $slug, implode(', ', self::SETTING_FIELDS)));
            }

            $canonicalization = $site['canonicalization'] ?? null;
            if (null !== $canonicalization) {
                if (!is_array($canonicalization)) {
                    throw new \InvalidArgumentException(sprintf('Site "%s" canonicalization must be a map.', $slug));
                }
                try {
                    $this->canonicalProfileRegistry->validate($canonicalization);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(sprintf('Site "%s" has invalid canonicalization: %s', $slug, $e->getMessage()), previous: $e);
                }
            }

            $definitions[$slug] = [
                'name' => $site['name'],
                'domains' => array_map(strtolower(...), $site['domains'] ?? []),
                'families' => $site['families'] ?? [],
                'extractors' => $site['extractors'] ?? [],
                'enabled' => (bool) ($site['enabled'] ?? true),
                'defaults' => $defaults,
                'canonicalization' => $canonicalization,
            ];
        }

        return $definitions;
    }
}
