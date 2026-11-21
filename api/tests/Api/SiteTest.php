<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Site;
use App\Entity\SiteSetting;
use Doctrine\ORM\EntityManagerInterface;

class SiteTest extends ApiTestCase
{
    use SiteTestTrait;

    private Client $client;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->client = static::createClient();
        $this->syncSites();
    }

    public function testListsSites(): void
    {
        $data = $this->get('/sites', ['itemsPerPage' => 1000]);

        $this->assertResponseIsSuccessful();

        $twitter = $this->findSlug($data['member'], 'twitter');
        $this->assertSame('Twitter / X', $twitter['name']);
        $this->assertTrue($twitter['curated']);
        $this->assertTrue($twitter['enabled']);
        $this->assertContains('x.com', $twitter['domains']);
        $this->assertContains('pbs.twimg.com', $twitter['domains'], 'tool host within a curated domain');
        $this->assertNotContains('example.onion', $twitter['domains'], 'tool host outside the curated domains');
        $this->assertArrayNotHasKey('extractors', $twitter, 'extractors are only in the item');
        $this->assertArrayNotHasKey('id', $twitter);
    }

    public function testSiteWithoutDefaultsHasNullDefaults(): void
    {
        $twitter = $this->findSlug($this->get('/sites', ['itemsPerPage' => 1000])['member'], 'twitter');

        $this->assertSame(['sendCookies' => null, 'sendUserAgent' => null, 'sendReferrer' => null, 'downloader' => null], $twitter['defaults']);
    }

    public function testExposesSiteDefaults(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['slug' => 'twitter']);
        $site->setDefaultSetting(($site->getDefaultSetting() ?? new SiteSetting())
            ->setSendCookies(true)
            ->setSendReferrer(false)
            ->setDownloader('gallery-dl-cli'));
        $em->flush();

        try {
            $expected = ['sendCookies' => true, 'sendUserAgent' => null, 'sendReferrer' => false, 'downloader' => 'gallery-dl-cli'];
            $this->assertSame($expected, $this->findSlug($this->get('/sites', ['itemsPerPage' => 1000])['member'], 'twitter')['defaults']);
            $this->assertSame($expected, $this->get('/sites/twitter')['defaults']);
        } finally {
            // The database isn't reset between tests, and app:sites:sync keeps defaults that sites.yaml doesn't set
            $em->createQuery('DELETE FROM '.SiteSetting::class.' s WHERE s.site = :site')->execute(['site' => $site]);
            $em->clear();
        }
    }

    public function testSearchMatchesNameAndDomain(): void
    {
        $byName = $this->get('/sites', ['search' => 'TWITTER']);
        $byDomain = $this->get('/sites', ['search' => 'youtu.be']);

        $this->assertNotNull($this->findSlug($byName['member'], 'twitter'));
        $this->assertNull($this->findSlug($byName['member'], 'youtube'));
        $this->assertNotNull($this->findSlug($byDomain['member'], 'youtube'));
        $this->assertNull($this->findSlug($byDomain['member'], 'twitter'));
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $this->assertSame([], $this->get('/sites', ['search' => '%'])['member']);
    }

    public function testEnabledFilter(): void
    {
        $this->assertSame([], array_filter($this->get('/sites', ['enabled' => 'false'])['member'], static fn (array $s): bool => 'twitter' === $s['slug']));
        $this->assertNotNull($this->findSlug($this->get('/sites', ['enabled' => 'true', 'itemsPerPage' => 1000])['member'], 'twitter'));
    }

    public function testGetSiteBySlugIncludesExtractors(): void
    {
        $data = $this->get('/sites/twitter');

        $this->assertResponseIsSuccessful();
        $this->assertSame('/sites/twitter', $data['@id']);
        $this->assertContains(['downloader' => 'gallery-dl-cli', 'key' => 'twitter'], $data['extractors']);
        $this->assertContains(['downloader' => 'yt-dlp-cli', 'key' => 'TwitterSpaces'], $data['extractors']);
    }

    public function testUnknownSlugIs404(): void
    {
        $this->get('/sites/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSitesAreReadOnly(): void
    {
        $this->client->request('POST', '/sites', ['json' => ['slug' => 'new', 'name' => 'New'], 'headers' => self::auth('admin')]);
        $this->assertResponseStatusCodeSame(405);

        $this->client->request('DELETE', '/sites/twitter', ['headers' => self::auth('admin')]);
        $this->assertResponseStatusCodeSame(405);
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/sites');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSyncIsIdempotent(): void
    {
        $before = $this->get('/sites/twitter');
        $this->syncSites();
        $after = $this->get('/sites/twitter');

        $this->assertSame($before['domains'], $after['domains']);
        $this->assertSame($before['extractors'], $after['extractors']);
    }

    private function get(string $uri, array $query = []): array
    {
        return self::withoutJsonLdKeywords($this->client->request('GET', $uri, ['query' => $query, 'headers' => self::auth()])->toArray(false));
    }

    private function findSlug(array $sites, string $slug): ?array
    {
        foreach ($sites as $site) {
            if ($site['slug'] === $slug) {
                return $site;
            }
        }

        return null;
    }
}
