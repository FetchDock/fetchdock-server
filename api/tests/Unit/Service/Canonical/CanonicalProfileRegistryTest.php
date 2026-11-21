<?php

namespace App\Tests\Unit\Service\Canonical;

use App\Entity\Site;
use App\Entity\SiteCanonicalizationOverride;
use App\Service\Canonical\CanonicalProfileRegistry;
use App\Tests\Double\CanonicalKeyGenerators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class CanonicalProfileRegistryTest extends TestCase
{
    public function testCodeProfilesExtendTheDefaultProfile(): void
    {
        $registry = new CanonicalProfileRegistry([
            'default' => ['dropParams' => ['utm_*']],
            'site' => ['dropParams' => ['ref'], 'stripWww' => true],
        ]);

        $profile = $registry->get('site');
        $this->assertSame(['utm_*', 'ref'], $profile->dropParams);
        $this->assertTrue($profile->stripWww);
        $this->assertTrue($profile->useExtractorId);
    }

    public function testSiteUsesTheProfileNamedAfterItsSlug(): void
    {
        $registry = CanonicalKeyGenerators::profileRegistry();

        $this->assertSame('twitter', $registry->forSite(new Site()->setSlug('twitter'))->name);
        $this->assertSame('default', $registry->forSite(new Site()->setSlug('unknown-site'))->name);
        $this->assertSame('default', $registry->forSite(null)->name);
    }

    public function testSiteSettingsSelectAndAdjustTheProfile(): void
    {
        $registry = CanonicalKeyGenerators::profileRegistry();
        $site = new Site()->setSlug('mirror')->setCanonicalization(['profile' => 'reddit', 'useExtractorId' => false, 'dropParams' => ['sort']]);

        $profile = $registry->forSite($site);

        $this->assertSame('reddit', $profile->name);
        $this->assertFalse($profile->useExtractorId);
        $this->assertTrue($profile->stripTrailingSlash);
        $this->assertContains('sort', $profile->dropParams);
        $this->assertContains('utm_*', $profile->dropParams);
    }

    public function testAdminOverrideWinsOverSiteSettings(): void
    {
        $registry = CanonicalKeyGenerators::profileRegistry();
        $site = new Site()->setSlug('mirror')->setCanonicalization(['profile' => 'reddit', 'stripWww' => false, 'dropParams' => ['sort']]);
        $site->setCanonicalizationOverride(new SiteCanonicalizationOverride()->setProfile('twitter')->setStripWww(null)->setUseExtractorId(false)->setDropParams(['page']));

        $profile = $registry->forSite($site);

        $this->assertSame('twitter', $profile->name);
        // Null inherits: the site setting applies
        $this->assertFalse($profile->stripWww);
        $this->assertFalse($profile->useExtractorId);
        $this->assertContains('sort', $profile->dropParams);
        $this->assertContains('page', $profile->dropParams);
        $this->assertSame(['tweet' => [1]], $profile->idGroups);
    }

    public function testSiteProfilesAreCachedUntilInvalidated(): void
    {
        $registry = new CanonicalProfileRegistry(
            ['default' => [], 'reddit' => ['stripWww' => true]],
            new TagAwareAdapter(new ArrayAdapter()),
        );
        $site = new Site()->setSlug('cached');

        $this->assertFalse($registry->forSite($site)->stripWww);
        $site->setCanonicalizationOverride(new SiteCanonicalizationOverride()->setProfile('reddit'));
        $this->assertFalse($registry->forSite($site)->stripWww, 'cached');

        $registry->invalidate();
        $this->assertTrue($registry->forSite($site)->stripWww);
    }

    public function testInvalidStoredSiteSettingsFallBackToTheBaseProfile(): void
    {
        $site = new Site()->setSlug('twitter')->setCanonicalization(['stripWww' => 'yes']);
        $registry = CanonicalKeyGenerators::profileRegistry();

        // Never breaks submissions: the stored value is ignored
        $this->assertEquals($registry->get('twitter'), $registry->forSite($site));
    }

    public static function invalidSettings(): iterable
    {
        yield 'unknown profile' => [['profile' => 'nope']];
        yield 'unknown field' => [['foo' => true]];
        yield 'pathRewrites are code only' => [['pathRewrites' => [['pattern' => '~a~', 'replacement' => 'b']]]];
        yield 'non-boolean flag' => [['stripWww' => 'yes']];
        yield 'non-list params' => [['dropParams' => 'utm_*']];
        yield 'invalid idGroups' => [['idGroups' => ['tweet' => ['1']]]];
    }

    #[DataProvider('invalidSettings')]
    public function testValidateRejectsInvalidSettings(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalKeyGenerators::profileRegistry()->validate($settings);
    }

    public function testValidateAcceptsValidSettings(): void
    {
        CanonicalKeyGenerators::profileRegistry()->validate(['profile' => 'twitter', 'keepParams' => ['id'], 'idGroups' => ['*' => [0]]]);

        $this->addToAssertionCount(1);
    }
}
