<?php

namespace App\Service\Privacy;

use App\Model\PrivacyPolicyAnswers;

/**
 * Everything the policy text is generated from (ADR 0013). Two inputs with the same hash() render the same text.
 */
final readonly class PolicyInputs
{
    /**
     * @param array<string, mixed> $configuration the runtime configuration the text depends on (PolicyInputsBuilder)
     * @param array<string, mixed> $inventory     privacy/data-inventory.yaml, prepared for the template
     */
    public function __construct(
        public string $textVersion,
        /** PolicyTextVersion::inputsDigest(): the inventory and template files */
        public string $textDigest,
        public string $appVersion,
        public PrivacyPolicyAnswers $answers,
        public array $configuration,
        public array $inventory,
    ) {
    }

    public function answersHash(): string
    {
        return $this->answers->hash();
    }

    public function configurationHash(): string
    {
        return hash('sha256', json_encode($this->configuration, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * sha256 over all inputs. The inventory is covered by the text digest.
     */
    public function hash(): string
    {
        return hash('sha256', implode("\n", [
            $this->textVersion,
            $this->textDigest,
            $this->appVersion,
            $this->answersHash(),
            $this->configurationHash(),
        ]));
    }
}
