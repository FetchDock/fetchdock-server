<?php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\SiteExtractor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    public function findOneBySlug(string $slug): ?Site
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * The site that lists the host in its domains, if any.
     */
    public function findOneByDomain(string $host): ?Site
    {
        // Domains are stored as a JSON array of lowercase strings: match the quoted host.
        $needle = '%'.addcslashes(json_encode(strtolower($host), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '%_\\').'%';

        return $this->createQueryBuilder('s')
            ->andWhere('JSONB_TEXT(s.domains) LIKE :needle')
            ->setParameter('needle', $needle)
            ->orderBy('s.curated', 'DESC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Domains of all enabled sites that have an extractor for the given downloader.
     *
     * @return list<string>
     */
    public function findDomainsByDownloader(string $downloader): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.domains')
            ->innerJoin(SiteExtractor::class, 'e', 'WITH', 'e.site = s')
            ->andWhere('e.downloader = :downloader')
            ->andWhere('s.enabled = true')
            ->setParameter('downloader', $downloader)
            ->distinct()
            ->getQuery()
            ->getArrayResult();

        $domains = [];
        foreach ($rows as $row) {
            foreach ($row['domains'] as $domain) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }
}
