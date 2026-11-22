<?php

namespace App\Tests\Integration\Privacy;

use App\Entity\PrivacyPolicySettings;
use App\Enum\PrivacyPolicyRevisionReason;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicyRevisionRepository;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Privacy\PendingPolicyUpdate;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PrivacyPolicyRevisionsTest extends KernelTestCase
{
    use PolicyServicesTrait;

    private EntityManagerInterface $entityManager;
    private PrivacyPolicySettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->entityManager->getConnection()->executeStatement('DELETE FROM privacy_policy_revision');
        $this->settings = self::getContainer()->get(PrivacyPolicySettingsRepository::class)->get();
        $this->settings->setViewAllRoleSeenAt(null);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testNoRevisionWhileTheAnswersAreIncomplete(): void
    {
        $this->saveAnswers(['instanceName' => 'Family downloads']);

        self::assertNull($this->revisions()->publish());
        self::assertNull(self::getContainer()->get(PrivacyPolicyRevisionRepository::class)->findLatest());
    }

    public function testCreatesARevisionOnlyWhenExplicitlyPublished(): void
    {
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());
        $revisions = $this->revisions();

        // Reading latest or pendingUpdate never publishes automatically
        self::assertNull($revisions->latest());
        $initial = $revisions->pendingUpdate();
        self::assertTrue($initial->initial);
        self::assertTrue($initial->hasChanges());
        self::assertFalse($initial->isOutdated());
        self::assertSame([PendingPolicyUpdate::REASON_INITIAL], $initial->reasons());
        self::assertNull($revisions->latest());

        $first = $revisions->publish(new \DateTimeImmutable('2026-11-01 10:00:00'));
        self::assertNotNull($first);
        self::assertSame(1, $first->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::INITIAL, $first->getReason());
        self::assertSame('0.9.0', $first->getAppVersion());
        self::assertStringContainsString('FetchDock 0.9.0, revision 1.', $first->getMarkdown());
        self::assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $first->getHtml());
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00'), $first->getCreatedAt());

        self::assertFalse($revisions->pendingUpdate()->hasChanges());
        self::assertSame($first, $revisions->publish(), 'nothing changed');

        // A patch release that changes only appVersion (same policy text and config) is not outdated
        $patchRevisions = $this->revisions($this->inputsBuilder(appVersion: '0.9.1'));
        self::assertFalse($patchRevisions->pendingUpdate()->hasChanges());
        self::assertFalse($patchRevisions->pendingUpdate()->isOutdated());
        self::assertSame($first, $patchRevisions->publish());

        // Saving different answers marks answers pending, without marking the published policy outdated
        $this->saveAnswers(['instanceName' => 'Our downloads'] + PrivacyPolicyAnswersTest::complete());
        $pendingAnswers = $revisions->pendingUpdate();
        self::assertTrue($pendingAnswers->answersChanged);
        self::assertFalse($pendingAnswers->isOutdated());
        self::assertSame([PendingPolicyUpdate::REASON_ANSWERS], $pendingAnswers->reasons());
        self::assertSame(1, $revisions->latest()->getNumber());

        $second = $revisions->publish();
        self::assertSame(2, $second->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::ANSWERS, $second->getReason());
        self::assertStringContainsString('# Privacy policy of Our downloads', $second->getMarkdown());

        // Changing runtime configuration marks the published policy outdated until explicitly published
        $this->settings->setViewAllRoleSeenAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $pendingConfig = $revisions->pendingUpdate();
        self::assertTrue($pendingConfig->configurationChanged);
        self::assertTrue($pendingConfig->isOutdated());
        self::assertSame([PendingPolicyUpdate::REASON_CONFIGURATION], $pendingConfig->reasons());
        self::assertSame(2, $revisions->latest()->getNumber());

        $third = $revisions->publish();
        self::assertSame(3, $third->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::CONFIGURATION, $third->getReason());

        // Simulating a previously published revision from an older policy text version (0.3 -> current)
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE privacy_policy_revision SET text_version = '0.3' WHERE number = 3",
        );
        $this->entityManager->clear();
        $pendingText = $revisions->pendingUpdate();
        self::assertTrue($pendingText->policyTextChanged);
        self::assertTrue($pendingText->isOutdated());
        self::assertSame('0.3', $pendingText->publishedTextVersion);
        self::assertSame([PendingPolicyUpdate::REASON_POLICY_TEXT], $pendingText->reasons());
        self::assertNotEmpty($pendingText->textChanges);

        $fourth = $this->revisions($this->inputsBuilder(appVersion: '0.9.1'))->publish();
        self::assertSame(4, $fourth->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::APP_VERSION, $fourth->getReason());
        self::assertSame('0.9.1', $fourth->getAppVersion());

        $this->entityManager->clear();
        $repository = self::getContainer()->get(PrivacyPolicyRevisionRepository::class);
        self::assertSame([4, 3, 2, 1], array_map(static fn ($revision): int => $revision->getNumber(), $repository->findAllNewestFirst()));
        $old = $repository->findOneByNumber(1);
        self::assertSame($first->getMarkdown(), $old->getMarkdown(), 'old revisions stay readable and unchanged');
        self::assertSame($first->getHtml(), $old->getHtml());
    }

    public function testPreviewRendersWithoutCreatingARevision(): void
    {
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());

        $preview = $this->revisions()->preview(PrivacyPolicyAnswers::fromArray(['instanceName' => 'Draft name']));

        self::assertStringContainsString('# Privacy policy of Draft name', $preview->markdown);
        self::assertStringContainsString('This text is not published.', $preview->markdown);
        self::assertNull(self::getContainer()->get(PrivacyPolicyRevisionRepository::class)->findLatest());
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function saveAnswers(array $answers): void
    {
        $this->settings->setAnswers(PrivacyPolicyAnswers::fromArray($answers));
        $this->entityManager->flush();
    }
}
