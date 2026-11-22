<?php

namespace App\Model;

/**
 * The operator's answers for the generated privacy policy (ADR 0013), normalized to a fixed shape: unknown keys are
 * dropped, empty strings become null, and numbers that aren't non-negative integers become null. Validation (email,
 * country codes, URLs) belongs to the admin endpoint; saving incomplete answers is allowed, and missing() lists what
 * the policy still needs before it can be published.
 */
final readonly class PrivacyPolicyAnswers
{
    /** Dotted paths of the answers the policy can't do without */
    public const array REQUIRED = [
        'instanceName',
        'operator.name',
        'operator.contactEmail',
        'identityProvider.name',
        'hosting.provider',
        'hosting.country',
        'retention.logsDays',
        'legalBasis',
    ];

    public const array LEGAL_BASES = ['contract', 'legitimate_interest'];

    public const int ADDITIONAL_TEXT_MAX_LENGTH = 10000;

    /**
     * @param array{
     *     instanceName: ?string,
     *     operator: array{name: ?string, address: ?string, contactEmail: ?string, dpoContact: ?string},
     *     identityProvider: array{name: ?string, privacyPolicyUrl: ?string},
     *     hosting: array{provider: ?string, country: ?string},
     *     processors: list<array{name: string, purpose: ?string, country: ?string}>,
     *     retention: array{logsDays: ?int, backups: ?string, softDeletedJobsDays: ?int, reportsDays: ?int},
     *     legalBasis: ?string,
     *     supervisoryAuthority: ?string,
     *     additionalText: ?string,
     * } $data
     */
    private function __construct(private array $data)
    {
    }

    public static function empty(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function fromArray(array $answers): self
    {
        $operator = self::map($answers['operator'] ?? null);
        $identityProvider = self::map($answers['identityProvider'] ?? null);
        $hosting = self::map($answers['hosting'] ?? null);
        $retention = self::map($answers['retention'] ?? null);

        $processors = [];
        foreach (\is_array($answers['processors'] ?? null) ? $answers['processors'] : [] as $processor) {
            $processor = self::map($processor);
            $name = self::string($processor['name'] ?? null);
            if (null !== $name) {
                $processors[] = [
                    'name' => $name,
                    'purpose' => self::string($processor['purpose'] ?? null),
                    'country' => self::country($processor['country'] ?? null),
                ];
            }
        }

        $legalBasis = self::string($answers['legalBasis'] ?? null);
        $additionalText = self::string($answers['additionalText'] ?? null, multiline: true);

        return new self([
            'instanceName' => self::string($answers['instanceName'] ?? null),
            'operator' => [
                'name' => self::string($operator['name'] ?? null),
                'address' => self::string($operator['address'] ?? null, multiline: true),
                'contactEmail' => self::string($operator['contactEmail'] ?? null),
                'dpoContact' => self::string($operator['dpoContact'] ?? null),
            ],
            'identityProvider' => [
                'name' => self::string($identityProvider['name'] ?? null),
                'privacyPolicyUrl' => self::string($identityProvider['privacyPolicyUrl'] ?? null),
            ],
            'hosting' => [
                'provider' => self::string($hosting['provider'] ?? null),
                'country' => self::country($hosting['country'] ?? null),
            ],
            'processors' => $processors,
            'retention' => [
                'logsDays' => self::days($retention['logsDays'] ?? null),
                'backups' => self::string($retention['backups'] ?? null),
                'softDeletedJobsDays' => self::days($retention['softDeletedJobsDays'] ?? null),
                'reportsDays' => self::days($retention['reportsDays'] ?? null),
            ],
            'legalBasis' => \in_array($legalBasis, self::LEGAL_BASES, true) ? $legalBasis : null,
            'supervisoryAuthority' => self::string($answers['supervisoryAuthority'] ?? null),
            'additionalText' => null === $additionalText ? null : mb_substr($additionalText, 0, self::ADDITIONAL_TEXT_MAX_LENGTH),
        ]);
    }

    /**
     * @return array<string, mixed> the normalized answers, always with every key
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The required answers that are still empty, as dotted paths in the order of REQUIRED.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, function (string $path): bool {
            $value = $this->data;
            foreach (explode('.', $path) as $key) {
                $value = $value[$key] ?? null;
            }

            return null === $value;
        }));
    }

    public function isComplete(): bool
    {
        return [] === $this->missing();
    }

    /**
     * sha256 of the normalized answers, one of the inputs of a policy revision.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function string(mixed $value, bool $multiline = false): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = $multiline ? trim($value) : trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $value ? null : $value;
    }

    /** ISO 3166-1 alpha-2 codes are stored upper case; the admin endpoint validates them */
    private static function country(mixed $value): ?string
    {
        $value = self::string($value);

        return null === $value ? null : mb_strtoupper($value);
    }

    private static function days(mixed $value): ?int
    {
        if (\is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        return \is_int($value) && $value >= 0 ? $value : null;
    }
}
