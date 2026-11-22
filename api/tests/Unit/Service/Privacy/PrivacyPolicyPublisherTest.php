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
use App\Service\Privacy\PrivacyPolicyPublisher;
use App\Service\Privacy\PrivacyPolicyRevisions;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PrivacyPolicyPublisherTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'custom wins over everything' => ['https://example.org/privacy', IntegrityStatus::MODIFIED, false, PrivacyPolicyStatus::CUSTOM, null, 'https://example.org/privacy'];
        yield 'modified code' => ['', IntegrityStatus::MODIFIED, true, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INTEGRITY_MODIFIED, null];
        yield 'unverified code before incomplete answers' => ['', IntegrityStatus::UNKNOWN, false, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INTEGRITY_UNKNOWN, null];
        yield 'incomplete answers' => ['', IntegrityStatus::VERIFIED, false, PrivacyPolicyStatus::UNAVAILABLE, PrivacyPolicyUnavailableReason::INCOMPLETE, null];
        yield 'generated' => ['', IntegrityStatus::VERIFIED, true, PrivacyPolicyStatus::GENERATED, null, 'https://fetchdock.example/privacy'];
    }

    #[DataProvider('cases')]
    public function testResolvesTheStatus(string $customUrl, IntegrityStatus $integrity, bool $complete, PrivacyPolicyStatus $status, ?PrivacyPolicyUnavailableReason $reason, ?string $url): void
    {
        $revision = new PrivacyPolicyRevision(1, PrivacyPolicyRevisionReason::INITIAL, '0.3', '0.9.0', 'a', 'b', 'c', '# Policy', '<h1>Policy</h1>');
        $revisions = $this->createMock(PrivacyPolicyRevisions::class);
        $revisions->expects(PrivacyPolicyStatus::GENERATED === $status ? self::once() : self::never())->method('sync')->willReturn($revision);

        $publisher = $this->publisher($customUrl, $integrity, $complete, $revisions);

        self::assertSame([$status, $reason], $publisher->resolve());
        self::assertSame($url, $publisher->url());
        self::assertSame(PrivacyPolicyStatus::GENERATED === $status ? $revision : null, $publisher->currentRevision());
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
