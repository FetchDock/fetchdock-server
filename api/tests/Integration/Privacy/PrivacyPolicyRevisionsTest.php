<?php

namespace App\Tests\Integration\Privacy;

use App\Entity\PrivacyPolicySettings;
use App\Enum\PrivacyPolicyRevisionReason;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicyRevisionRepository;
use App\Repository\PrivacyPolicySettingsRepository;
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

        self::assertNull($this->revisions()->sync());
        self::assertNull(self::getContainer()->get(PrivacyPolicyRevisionRepository::class)->findLatest());
    }

    public function testCreatesARevisionOnlyWhenTheInputsChange(): void
    {
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());
        $revisions = $this->revisions();

        $first = $revisions->sync(new \DateTimeImmutable('2026-11-01 10:00:00'));
        self::assertNotNull($first);
        self::assertSame(1, $first->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::INITIAL, $first->getReason());
        self::assertSame('0.9.0', $first->getAppVersion());
        self::assertStringContainsString('FetchDock 0.9.0, revision 1.', $first->getMarkdown());
        self::assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $first->getHtml());
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00'), $first->getCreatedAt());

        self::assertSame($first, $revisions->sync(), 'nothing changed');
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());
        self::assertSame($first, $revisions->sync(), 'saving the same answers again');

        $this->saveAnswers(['instanceName' => 'Our downloads'] + PrivacyPolicyAnswersTest::complete());
        $second = $revisions->sync();
        self::assertSame(2, $second->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::ANSWERS, $second->getReason());
        self::assertStringContainsString('# Privacy policy of Our downloads', $second->getMarkdown());

        $this->settings->setViewAllRoleSeenAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $third = $revisions->sync();
        self::assertSame(3, $third->getNumber());
        self::assertSame(PrivacyPolicyRevisionReason::CONFIGURATION, $third->getReason());

        $fourth = $this->revisions($this->inputsBuilder(appVersion: '0.9.1'))->sync();
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
