<?php

namespace App\Service\Privacy;

use App\Entity\PrivacyPolicyRevision;
use App\Enum\IntegrityStatus;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Integrity\IntegrityStatusResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Decides which privacy policy the instance publishes (ADR 0013):
 *
 *   PRIVACY_POLICY_URL set                                                    → custom
 *   integrity verified, required answers complete, and a revision published   → generated (/privacy, last approved revision)
 *   otherwise                                                                 → unavailable, with the reason
 *
 * Revisions are never published automatically: when the software's policy text, runtime configuration or saved answers
 * change, the last operator-approved revision stays served until an administrator explicitly calls publish().
 */
class PrivacyPolicyPublisher
{
    public const string ROUTE = 'app_privacy_policy';

    public function __construct(
        private readonly IntegrityStatusResolver $integrityStatusResolver,
        private readonly PrivacyPolicySettingsRepository $settingsRepository,
        private readonly PrivacyPolicyRevisions $revisions,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.privacy_policy_url%')]
        private readonly string $customUrl,
    ) {
    }

    public function status(): PrivacyPolicyStatus
    {
        return $this->resolve()[0];
    }

    /**
     * @return array{PrivacyPolicyStatus, ?PrivacyPolicyUnavailableReason}
     */
    public function resolve(): array
    {
        if ('' !== $this->customUrl) {
            return [PrivacyPolicyStatus::CUSTOM, null];
        }
        $reason = match ($this->integrityStatusResolver->effectiveStatus()) {
            IntegrityStatus::MODIFIED => PrivacyPolicyUnavailableReason::INTEGRITY_MODIFIED,
            IntegrityStatus::UNKNOWN => PrivacyPolicyUnavailableReason::INTEGRITY_UNKNOWN,
            IntegrityStatus::VERIFIED => match (true) {
                !$this->settingsRepository->get()->getAnswers()->isComplete() => PrivacyPolicyUnavailableReason::INCOMPLETE,
                null === $this->revisions->latest() => PrivacyPolicyUnavailableReason::NOT_PUBLISHED,
                default => null,
            },
        };

        return null === $reason ? [PrivacyPolicyStatus::GENERATED, null] : [PrivacyPolicyStatus::UNAVAILABLE, $reason];
    }

    /**
     * The policy's address for clients: the custom URL, the absolute /privacy URL while generated, otherwise null.
     */
    public function url(?PrivacyPolicyStatus $status = null): ?string
    {
        return match ($status ?? $this->status()) {
            PrivacyPolicyStatus::CUSTOM => $this->customUrl,
            PrivacyPolicyStatus::GENERATED => $this->urlGenerator->generate(self::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            PrivacyPolicyStatus::UNAVAILABLE => null,
        };
    }

    /**
     * The last operator-approved revision while the generated policy is published, otherwise null. Read-only.
     */
    public function currentRevision(?PrivacyPolicyStatus $status = null): ?PrivacyPolicyRevision
    {
        return PrivacyPolicyStatus::GENERATED === ($status ?? $this->status()) ? $this->revisions->latest() : null;
    }

    /**
     * Differences between the last approved revision and the current inputs. Read-only.
     */
    public function pendingUpdate(?\DateTimeImmutable $now = null): PendingPolicyUpdate
    {
        return $this->revisions->pendingUpdate($now);
    }

    /**
     * Whether a published generated policy no longer matches the running software's policy text or configuration.
     */
    public function isOutdated(?PrivacyPolicyStatus $status = null, ?\DateTimeImmutable $now = null): bool
    {
        return PrivacyPolicyStatus::GENERATED === ($status ?? $this->status()) && $this->revisions->pendingUpdate($now)->isOutdated();
    }

    /**
     * Explicitly approves and publishes the current generated policy as a new revision.
     */
    public function publish(\DateTimeImmutable $now = new \DateTimeImmutable()): PrivacyPolicyRevision
    {
        if ('' !== $this->customUrl) {
            throw new ConflictHttpException('PRIVACY_POLICY_URL is set, so this instance publishes a custom privacy policy.');
        }
        if (IntegrityStatus::VERIFIED !== $this->integrityStatusResolver->effectiveStatus()) {
            throw new ConflictHttpException('The generated privacy policy can only be published while the code integrity check reads verified.');
        }
        $revision = $this->revisions->publish($now);
        if (null === $revision) {
            throw new UnprocessableEntityHttpException('Complete all required answers before publishing the privacy policy.');
        }

        return $revision;
    }
}
