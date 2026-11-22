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
 * Revisions of the generated privacy policy (ADR 0013).
 *
 * A new revision is ONLY created when an administrator explicitly approves and publishes it (publish()). Passive reads
 * never create or modify revisions; pendingUpdate() reports when the running policy text, runtime configuration or
 * saved answers differ from the last approved revision. Old revisions are kept unchanged.
 */
class PrivacyPolicyRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrivacyPolicySettingsRepository $settingsRepository,
        private readonly PrivacyPolicyRevisionRepository $revisionRepository,
        private readonly PolicyInputsBuilder $inputsBuilder,
        private readonly PolicyRenderer $renderer,
        private readonly PolicyTextVersion $textVersion,
    ) {
    }

    /**
     * The latest operator-approved revision, or null if none has been published yet. Read-only.
     */
    public function latest(): ?PrivacyPolicyRevision
    {
        return $this->revisionRepository->findLatest();
    }

    /**
     * Compares the latest operator-approved revision against the current inputs without writing anything.
     */
    public function pendingUpdate(?\DateTimeImmutable $now = null): PendingPolicyUpdate
    {
        $settings = $this->settingsRepository->get();
        $inputs = $this->inputsBuilder->build($settings, now: $now ?? new \DateTimeImmutable());

        return $this->diff($this->revisionRepository->findLatest(), $inputs);
    }

    /**
     * Explicitly approves and publishes the current inputs as the next revision. Idempotent when nothing changed
     * since the latest revision. Returns null when required answers are incomplete.
     */
    public function publish(\DateTimeImmutable $now = new \DateTimeImmutable()): ?PrivacyPolicyRevision
    {
        $settings = $this->settingsRepository->get();
        $inputs = $this->inputsBuilder->build($settings, now: $now);
        if (!$inputs->answers->isComplete()) {
            return null;
        }
        $latest = $this->revisionRepository->findLatest();
        if (null !== $latest && !$this->diff($latest, $inputs)->hasChanges()) {
            return $latest;
        }

        // Concurrent publish requests wait on the settings row, then find the revision the first one created
        return $this->entityManager->wrapInTransaction(function () use ($now): ?PrivacyPolicyRevision {
            $settings = $this->settingsRepository->get(LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($settings);
            $inputs = $this->inputsBuilder->build($settings, now: $now);
            if (!$inputs->answers->isComplete()) {
                return null;
            }
            $latest = $this->revisionRepository->findLatest();
            $pending = $this->diff($latest, $inputs);
            if (null !== $latest && !$pending->hasChanges()) {
                return $latest;
            }

            $number = ($latest?->getNumber() ?? 0) + 1;
            $rendered = $this->renderer->render($inputs, $number);
            $revision = new PrivacyPolicyRevision(
                number: $number,
                reason: self::reason($pending),
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

    private function diff(?PrivacyPolicyRevision $latest, PolicyInputs $inputs): PendingPolicyUpdate
    {
        if (null === $latest) {
            return new PendingPolicyUpdate(
                initial: true,
                policyTextChanged: false,
                configurationChanged: false,
                answersChanged: false,
                publishedTextVersion: null,
                currentTextVersion: $inputs->textVersion,
                textChanges: [],
            );
        }

        $answersChanged = $latest->getAnswersHash() !== $inputs->answersHash();
        $configurationChanged = $latest->getConfigurationHash() !== $inputs->configurationHash();
        $publishedTextDigest = $this->textVersion->digestFor($latest->getTextVersion());
        $policyTextChanged = $latest->getTextVersion() !== $inputs->textVersion
            || null === $publishedTextDigest
            || $publishedTextDigest !== $inputs->textDigest;

        return new PendingPolicyUpdate(
            initial: false,
            policyTextChanged: $policyTextChanged,
            configurationChanged: $configurationChanged,
            answersChanged: $answersChanged,
            publishedTextVersion: $latest->getTextVersion(),
            currentTextVersion: $inputs->textVersion,
            textChanges: $policyTextChanged ? $this->textVersion->changesSince($latest->getTextVersion()) : [],
        );
    }

    private static function reason(PendingPolicyUpdate $pending): PrivacyPolicyRevisionReason
    {
        return match (true) {
            $pending->initial => PrivacyPolicyRevisionReason::INITIAL,
            $pending->policyTextChanged => PrivacyPolicyRevisionReason::APP_VERSION,
            $pending->configurationChanged => PrivacyPolicyRevisionReason::CONFIGURATION,
            default => PrivacyPolicyRevisionReason::ANSWERS,
        };
    }
}
