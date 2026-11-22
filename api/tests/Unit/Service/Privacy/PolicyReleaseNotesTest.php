<?php

namespace App\Tests\Unit\Service\Privacy;

use App\Service\Privacy\PolicyReleaseNotes;
use App\Service\Privacy\PolicyTextVersion;
use PHPUnit\Framework\TestCase;

final class PolicyReleaseNotesTest extends TestCase
{
    private PolicyReleaseNotes $releaseNotes;

    protected function setUp(): void
    {
        $this->releaseNotes = new PolicyReleaseNotes();
    }

    public function testNoSectionWhenNoVersionWasAdded(): void
    {
        $versions = [self::entry('1.0'), self::entry('1.1')];

        self::assertSame('', $this->releaseNotes->section($versions, $versions));
    }

    public function testListsTheVersionsAddedSinceThePreviousRelease(): void
    {
        $section = $this->releaseNotes->section(
            [self::entry('1.0')],
            [self::entry('1.0'), self::entry('1.1', 'Site cookies are kept for 7 days'), self::entry('1.2', 'Clarify log retention')],
        );

        self::assertStringStartsWith(PolicyReleaseNotes::HEADING."\n\n", $section);
        self::assertStringContainsString('(policy text version 1.0 → 1.2)', $section);
        self::assertStringContainsString("- **1.1:** Site cookies are kept for 7 days\n- **1.2:** Clarify log retention\n", $section);
        self::assertStringNotContainsString('**1.0:**', $section);
        self::assertStringContainsString('*Admin → Privacy policy*', $section);
        self::assertStringContainsString('`PRIVACY_POLICY_URL`', $section);
        self::assertStringNotContainsString('major change', $section);
    }

    public function testFlagsAMajorVersionChangeAfterOnePointZero(): void
    {
        $section = $this->releaseNotes->section([self::entry('1.2')], [self::entry('1.2'), self::entry('2.0', 'Forward reports by default')]);

        self::assertStringContainsString('(policy text version 1.2 → 2.0)', $section);
        self::assertStringContainsString('This is a major change', $section);
    }

    public function testDoesNotFlagDraftToOnePointZeroAsMajorChange(): void
    {
        $section = $this->releaseNotes->section([self::entry('0.3')], [self::entry('0.3'), self::entry('1.0', 'Reviewed policy text')]);

        self::assertStringContainsString('(policy text version 0.3 → 1.0)', $section);
        self::assertStringNotContainsString('major change', $section);
    }

    public function testIntroducesThePolicyWhenThePreviousReleaseHadNoVersions(): void
    {
        $section = $this->releaseNotes->section(null, [self::entry('0.1'), self::entry('1.0')]);

        self::assertStringStartsWith(PolicyReleaseNotes::HEADING."\n\n", $section);
        self::assertStringContainsString('policy text version 1.0', $section);
        self::assertStringNotContainsString('0.1', $section, 'Drafts before the first release are not listed');
    }

    public function testRejectsEditedVersions(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Policy text version 1.0 of the previous release was edited or removed');

        $this->releaseNotes->section([self::entry('1.0', digest: 'aaa')], [self::entry('1.0', digest: 'bbb'), self::entry('1.1')]);
    }

    public function testRejectsRemovedVersions(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->releaseNotes->section([self::entry('1.0'), self::entry('1.1')], [self::entry('1.0')]);
    }

    public function testRejectsEmptyCurrentVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->releaseNotes->section(null, []);
    }

    public function testTheCurrentVersionsProduceASectionSinceAReleaseWithoutThem(): void
    {
        $current = (new PolicyTextVersion(\dirname(__DIR__, 4)))->versions();

        self::assertStringContainsString($current[array_key_last($current)]['version'], $this->releaseNotes->section(null, $current));
        self::assertSame('', $this->releaseNotes->section($current, $current));
    }

    /**
     * @return array{version: string, digest: string, changes: string}
     */
    private static function entry(string $version, string $changes = 'Changed', ?string $digest = null): array
    {
        return ['version' => $version, 'digest' => $digest ?? hash('sha256', $version), 'changes' => $changes];
    }
}
