<?php

namespace App\Service\Privacy;

use App\Entity\PrivacyPolicyRevision;
use App\Enum\PrivacyPolicyRevisionReason;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicyRevisionRepository;
use App\Repository\PrivacyPolicySettingsRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revisions of the generated privacy policy (ADR 0013). A new revision is created when the inputs differ from the
 * latest revision: the operator saved different answers, the app version or policy text changed, or the runtime
 * configuration changed the text. Saving the same answers again creates none. Old revisions are kept.
 *
 * Whether the generated policy may be published (integrity verified, PRIVACY_POLICY_URL not set) is up to the
 * caller; sync() only refuses incomplete answers.
 */
class PrivacyPolicyRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrivacyPolicySettingsRepository $settingsRepository,
        private readonly PrivacyPolicyRevisionRepository $revisionRepository,
        private readonly PolicyInputsBuilder $inputsBuilder,
        private readonly PolicyRenderer $renderer,
    ) {
    }

    /**
     * The revision for the current inputs, created if they changed since the latest one. Null while the operator's
     * answers are incomplete.
     */
    public function sync(\DateTimeImmutable $now = new \DateTimeImmutable()): ?PrivacyPolicyRevision
    {
        $settings = $this->settingsRepository->get();
        $inputs = $this->inputsBuilder->build($settings, now: $now);
        if (!$inputs->answers->isComplete()) {
            return null;
        }
        $latest = $this->revisionRepository->findLatest();
        if (null !== $latest && $latest->getInputsHash() === $inputs->hash()) {
            return $latest;
        }

        // Concurrent requests wait on the settings row, then find the revision the first one created
        return $this->entityManager->wrapInTransaction(function () use ($now): PrivacyPolicyRevision {
            $settings = $this->settingsRepository->get(LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($settings);
            $inputs = $this->inputsBuilder->build($settings, now: $now);
            $latest = $this->revisionRepository->findLatest();
            if (null !== $latest && $latest->getInputsHash() === $inputs->hash()) {
                return $latest;
            }

            $number = ($latest?->getNumber() ?? 0) + 1;
            $rendered = $this->renderer->render($inputs, $number);
            $revision = new PrivacyPolicyRevision(
                number: $number,
                reason: self::reason($latest, $inputs),
                textVersion: $inputs->textVersion,
                appVersion: $inputs->appVersion,
                inputsHash: $inputs->hash(),
                answersHash: $inputs->answersHash(),
                configurationHash: $inputs->configurationHash(),
                markdown: $rendered->markdown,
                html: $rendered->html,
                createdAt: $now,
            );
            $this->entityManager->persist($revision);
            $this->entityManager->flush();

            return $revision;
        });
    }

    /**
     * Renders the policy without creating a revision, also with incomplete answers (missing ones stay empty).
     *
     * @param PrivacyPolicyAnswers|null $answers answers to preview instead of the saved ones
     */
    public function preview(?PrivacyPolicyAnswers $answers = null, \DateTimeImmutable $now = new \DateTimeImmutable()): RenderedPolicy
    {
        return $this->renderer->render($this->inputsBuilder->build($this->settingsRepository->get(), $answers, $now), null);
    }

    private static function reason(?PrivacyPolicyRevision $latest, PolicyInputs $inputs): PrivacyPolicyRevisionReason
    {
        return match (true) {
            null === $latest => PrivacyPolicyRevisionReason::INITIAL,
            $latest->getAnswersHash() !== $inputs->answersHash() => PrivacyPolicyRevisionReason::ANSWERS,
            $latest->getConfigurationHash() !== $inputs->configurationHash() => PrivacyPolicyRevisionReason::CONFIGURATION,
            // The app version, or the policy text within the same version (development builds)
            default => PrivacyPolicyRevisionReason::APP_VERSION,
        };
    }
}
