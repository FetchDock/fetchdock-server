<?php

namespace App\Tests\Unit\Service\Privacy;

use App\Entity\PrivacyPolicyRevision;
use App\Entity\PrivacyPolicySettings;
use App\Enum\IntegrityStatus;
use App\Enum\PrivacyPolicyRevisionReason;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Integrity\IntegrityStatusResolver;
use App\Service\Privacy\PendingPolicyUpdate;
use App\Service\Privacy\PrivacyPolicyPublisher;
use App\Service\Privacy\PrivacyPolicyRevisions;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PrivacyPolicyPublisherTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'custom wins over everything' => ['https://example.org/privacy', IntegrityStatus::MODIFIED, false, false, PrivacyPolicyStatus::CUSTOM, null, 'https://example.org/privacy'];
        yield 'modified code' => ['', IntegrityStatus::MODIFIED, true, true, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INTEGRITY_MODIFIED, null];
        yield 'unverified code before incomplete answers' => ['', IntegrityStatus::UNKNOWN, false, false, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INTEGRITY_UNKNOWN, null];
        yield 'incomplete answers' => ['', IntegrityStatus::VERIFIED, false, true, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INCOMPLETE, null];
        yield 'complete answers but not yet published' => ['', IntegrityStatus::VERIFIED, true, false, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::NOT_PUBLISHED, null];
        yield 'generated' => ['', IntegrityStatus::VERIFIED, true, true, PrivacyPolicyStatus::GENERATED, null, 'https://fetchdock.example/privacy'];
    }

    #[DataProvider('cases')]
    public function testResolvesTheStatus(string $customUrl, IntegrityStatus $integrity, bool $complete, bool $hasRevision, PrivacyPolicyStatus $status, ?PrivacyPolicyUnavailableReason $reason, ?string $url): void
    {
        $revision = $hasRevision ? new PrivacyPolicyRevision(1, PrivacyPolicyRevisionReason::INITIAL, '1.0', '0.9.0', 'a', 'b', 'c', '# Policy', '<h1>Policy</h1>') : null;
        $revisions = $this->createMock(PrivacyPolicyRevisions::class);
        $revisions->method('latest')->willReturn($revision);
        $revisions->expects(self::never())->method('publish');

        $publisher = $this->publisher($customUrl, $integrity, $complete, $revisions);

        self::assertSame([$status, $reason], $publisher->resolve());
        self::assertSame($url, $publisher->url());
        self::assertSame(PrivacyPolicyStatus::GENERATED === $status ? $revision : null, $publisher->currentRevision());
    }

    public function testIsOutdatedOnlyWhenGeneratedAndSoftwareOrConfigChanged(): void
    {
        $revision = new PrivacyPolicyRevision(1, PrivacyPolicyRevisionReason::INITIAL, '1.0', '0.9.0', 'a', 'b', 'c', '# Policy', '<h1>Policy</h1>');
        $revisions = $this->createStub(PrivacyPolicyRevisions::class);
        $revisions->method('latest')->willReturn($revision);
        $revisions->method('pendingUpdate')->willReturn(new PendingPolicyUpdate(false, true, false, false, '1.0', '1.1', [['version' => '1.1', 'changes' => 'Updated']]));

        self::assertTrue($this->publisher('', IntegrityStatus::VERIFIED, true, $revisions)->isOutdated());
        self::assertFalse($this->publisher('https://example.org/privacy', IntegrityStatus::VERIFIED, true, $revisions)->isOutdated());
        self::assertFalse($this->publisher('', IntegrityStatus::MODIFIED, true, $revisions)->isOutdated());
    }

    public function testPublishRejectsCustomPolicy(): void
    {
        $revisions = $this->createMock(PrivacyPolicyRevisions::class);
        $revisions->expects(self::never())->method('publish');

        $this->expectException(ConflictHttpException::class);
        $this->publisher('https://example.org/privacy', IntegrityStatus::VERIFIED, true, $revisions)->publish();
    }

    public function testPublishRejectsUnverifiedIntegrity(): void
    {
        $revisions = $this->createMock(PrivacyPolicyRevisions::class);
        $revisions->expects(self::never())->method('publish');

        $this->expectException(ConflictHttpException::class);
        $this->publisher('', IntegrityStatus::UNKNOWN, true, $revisions)->publish();
    }

    public function testPublishRejectsIncompleteAnswers(): void
    {
        $revisions = $this->createStub(PrivacyPolicyRevisions::class);
        $revisions->method('publish')->willReturn(null);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->publisher('', IntegrityStatus::VERIFIED, false, $revisions)->publish();
    }

    private function publisher(string $customUrl, IntegrityStatus $integrity, bool $complete, PrivacyPolicyRevisions $revisions): PrivacyPolicyPublisher
    {
        $resolver = $this->createStub(IntegrityStatusResolver::class);
        $resolver->method('effectiveStatus')->willReturn($integrity);
        $settings = new PrivacyPolicySettings()->setAnswers(PrivacyPolicyAnswers::fromArray($complete ? PrivacyPolicyAnswersTest::complete() : []));
        $repository = $this->createStub(PrivacyPolicySettingsRepository::class);
        $repository->method('get')->willReturn($settings);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://fetchdock.example/privacy');

        return new PrivacyPolicyPublisher($resolver, $repository, $revisions, $urlGenerator, $customUrl);
    }
}
