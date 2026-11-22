<?php

namespace App\Resource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\PrivacyPolicy\PrivacyPolicyHosting;
use App\Dto\PrivacyPolicy\PrivacyPolicyIdentityProvider;
use App\Dto\PrivacyPolicy\PrivacyPolicyOperator;
use App\Dto\PrivacyPolicy\PrivacyPolicyProcessor;
use App\Dto\PrivacyPolicy\PrivacyPolicyRetention;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Model\IntegritySummary;
use App\Model\PrivacyPolicyAnswers;
use App\State\Admin\AdminPrivacyPolicyProcessor;
use App\State\Admin\AdminPrivacyPolicyProvider;
use App\State\Admin\AdminPrivacyPolicyPublishProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The operator's answers for the generated privacy policy and its publication status (ADR 0013).
 */
#[ApiResource(
    shortName: 'AdminPrivacyPolicy',
    operations: [
        new Get(
            uriTemplate: '/admin/privacy_policy',
            description: 'The answers, what is still missing, whether the generated policy is published, and whether a review/publish is pending. The rendered text: GET /admin/privacy_policy/preview (HTML).',
        ),
        new Put(
            uriTemplate: '/admin/privacy_policy',
            description: 'Replaces the saved answers without changing the published policy. Incomplete answers can be saved. Call POST /admin/privacy_policy/publish after reviewing the preview to publish a new revision.',
            extraProperties: ['standard_put' => true],
        ),
        new Post(
            uriTemplate: '/admin/privacy_policy/publish',
            description: 'Explicitly approves and publishes the current generated privacy policy as a new revision. Only works while PRIVACY_POLICY_URL is unset, integrity reads verified, and required answers are complete.',
            read: false,
            deserialize: false,
            validate: false,
            processor: AdminPrivacyPolicyPublishProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['privacy_policy:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['privacy_policy:write'], 'allow_extra_attributes' => false],
    security: "is_granted('ROLE_ADMIN')",
    provider: AdminPrivacyPolicyProvider::class,
    processor: AdminPrivacyPolicyProcessor::class,
)]
final class AdminPrivacyPolicy
{
    /** The instance's name, used as the policy's title (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $instanceName = null;

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Valid]
    public PrivacyPolicyOperator $operator;

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Valid]
    public PrivacyPolicyIdentityProvider $identityProvider;

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Valid]
    public PrivacyPolicyHosting $hosting;

    /**
     * Other service providers that process data for the operator.
     *
     * @var list<PrivacyPolicyProcessor>
     */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Valid]
    #[Assert\Count(max: 50)]
    public array $processors = [];

    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Valid]
    public PrivacyPolicyRetention $retention;

    /** contract, or legitimate_interest (personal and family installs) (required) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Choice(choices: PrivacyPolicyAnswers::LEGAL_BASES)]
    public ?string $legalBasis = null;

    /** The supervisory authority users can complain to */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: 255)]
    public ?string $supervisoryAuthority = null;

    /** Markdown, appended as its own section (raw HTML is shown as text) */
    #[Groups(['privacy_policy:read', 'privacy_policy:write'])]
    #[Assert\Length(max: PrivacyPolicyAnswers::ADDITIONAL_TEXT_MAX_LENGTH)]
    public ?string $additionalText = null;

    /**
     * Required answers that are still empty, as dotted paths, e.g. "operator.contactEmail" (read-only).
     *
     * @var list<string>
     */
    #[Groups(['privacy_policy:read'])]
    public array $missing = [];

    /** Which policy the instance publishes (read-only) */
    #[Groups(['privacy_policy:read'])]
    public PrivacyPolicyStatus $status = PrivacyPolicyStatus::UNAVAILABLE;

    /** Why none is published (read-only) */
    #[Groups(['privacy_policy:read'])]
    public ?PrivacyPolicyUnavailableReason $reason = null;

    /** The published revision of the generated policy (read-only) */
    #[Groups(['privacy_policy:read'])]
    public ?int $revision = null;

    /** Policy text version of the last published revision (read-only) */
    #[Groups(['privacy_policy:read'])]
    public ?string $publishedTextVersion = null;

    /** Policy text version bundled with this FetchDock build (read-only) */
    #[Groups(['privacy_policy:read'])]
    public string $currentTextVersion = '';

    /** True when the published generated policy no longer reflects the running software's policy text or configuration (read-only) */
    #[Groups(['privacy_policy:read'])]
    public bool $outdated = false;

    /** True when an admin review and explicit publish is pending (initial publish or any change since the last revision) (read-only) */
    #[Groups(['privacy_policy:read'])]
    public bool $pendingPublish = false;

    /**
     * Why a publish is pending: "initial", or any subset of "policy_text", "configuration", "answers" (read-only).
     *
     * @var list<string>
     */
    #[Groups(['privacy_policy:read'])]
    public array $pendingReasons = [];

    /**
     * Entries added to privacy/policy-version.yaml since publishedTextVersion (read-only).
     *
     * @var list<array{version: string, changes: string}>
     */
    #[Groups(['privacy_policy:read'])]
    public array $pendingTextChanges = [];

    /** The integrity status; the generated policy is only published while it reads verified (read-only) */
    #[Groups(['privacy_policy:read'])]
    public IntegritySummary $integrity;

    public function __construct()
    {
        $this->operator = new PrivacyPolicyOperator();
        $this->identityProvider = new PrivacyPolicyIdentityProvider();
        $this->hosting = new PrivacyPolicyHosting();
        $this->retention = new PrivacyPolicyRetention();
        $this->integrity = new IntegritySummary();
    }

    public static function fromAnswers(PrivacyPolicyAnswers $answers): self
    {
        $data = $answers->toArray();
        $resource = new self();
        $resource->instanceName = $data['instanceName'];
        foreach ($data['operator'] as $key => $value) {
            $resource->operator->{$key} = $value;
        }
        foreach ($data['identityProvider'] as $key => $value) {
            $resource->identityProvider->{$key} = $value;
        }
        foreach ($data['hosting'] as $key => $value) {
            $resource->hosting->{$key} = $value;
        }
        foreach ($data['retention'] as $key => $value) {
            $resource->retention->{$key} = $value;
        }
        $resource->processors = array_map(static function (array $entry): PrivacyPolicyProcessor {
            $processor = new PrivacyPolicyProcessor();
            $processor->name = $entry['name'];
            $processor->purpose = $entry['purpose'];
            $processor->country = $entry['country'];

            return $processor;
        }, $data['processors']);
        $resource->legalBasis = $data['legalBasis'];
        $resource->supervisoryAuthority = $data['supervisoryAuthority'];
        $resource->additionalText = $data['additionalText'];
        $resource->missing = $answers->missing();

        return $resource;
    }

    public function toAnswers(): PrivacyPolicyAnswers
    {
        return PrivacyPolicyAnswers::fromArray([
            'instanceName' => $this->instanceName,
            'operator' => get_object_vars($this->operator),
            'identityProvider' => get_object_vars($this->identityProvider),
            'hosting' => get_object_vars($this->hosting),
            'processors' => array_map(static fn (PrivacyPolicyProcessor $processor): array => get_object_vars($processor), $this->processors),
            'retention' => get_object_vars($this->retention),
            'legalBasis' => $this->legalBasis,
            'supervisoryAuthority' => $this->supervisoryAuthority,
            'additionalText' => $this->additionalText,
        ]);
    }
}
