<?php

namespace App\Service\Privacy;

use App\Entity\PrivacyPolicyRevision;
use App\Enum\IntegrityStatus;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Integrity\IntegrityStatusResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Decides which privacy policy the instance publishes (ADR 0013):
 *
 *   PRIVACY_POLICY_URL set                                → custom
 *   integrity verified and the required answers complete  → generated (/privacy, the current revision)
 *   otherwise                                             → unavailable, with the reason
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
            IntegrityStatus::VERIFIED => $this->settingsRepository->get()->getAnswers()->isComplete() ? null : PrivacyPolicyUnavailableReason::INCOMPLETE,
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
     * The revision in force, created first if the inputs changed. Null unless the generated policy is published.
     */
    public function currentRevision(?PrivacyPolicyStatus $status = null): ?PrivacyPolicyRevision
    {
        return PrivacyPolicyStatus::GENERATED === ($status ?? $this->status()) ? $this->revisions->sync() : null;
    }
}
