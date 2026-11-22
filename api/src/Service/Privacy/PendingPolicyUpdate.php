<?php

namespace App\Service\Privacy;

/**
 * Difference between the last operator-approved privacy policy revision and the current inputs (ADR 0013).
 *
 * Revisions are never published automatically: this object tells the admin UI, public /privacy notice and startup
 * logs when an administrator needs to review and approve a new revision.
 */
final readonly class PendingPolicyUpdate
{
    public const string REASON_INITIAL = 'initial';
    public const string REASON_POLICY_TEXT = 'policy_text';
    public const string REASON_CONFIGURATION = 'configuration';
    public const string REASON_ANSWERS = 'answers';

    /**
     * @param list<array{version: string, changes: string}> $textChanges entries added to policy-version.yaml since $publishedTextVersion
     */
    public function __construct(
        public bool $initial,
        public bool $policyTextChanged,
        public bool $configurationChanged,
        public bool $answersChanged,
        public ?string $publishedTextVersion,
        public string $currentTextVersion,
        public array $textChanges = [],
    ) {
    }

    /**
     * Whether publishing now would create a new revision (initial publish or any changed input).
     */
    public function hasChanges(): bool
    {
        return $this->initial || $this->policyTextChanged || $this->configurationChanged || $this->answersChanged;
    }

    /**
     * Whether a published policy no longer matches the running software's policy text or configuration.
     * Draft answer edits alone do not make the published policy outdated.
     */
    public function isOutdated(): bool
    {
        return !$this->initial && ($this->policyTextChanged || $this->configurationChanged);
    }

    /**
     * Machine-readable reasons why a publish is pending, for the admin API and UI.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        if ($this->initial) {
            return [self::REASON_INITIAL];
        }

        $reasons = [];
        if ($this->policyTextChanged) {
            $reasons[] = self::REASON_POLICY_TEXT;
        }
        if ($this->configurationChanged) {
            $reasons[] = self::REASON_CONFIGURATION;
        }
        if ($this->answersChanged) {
            $reasons[] = self::REASON_ANSWERS;
        }

        return $reasons;
    }
}
