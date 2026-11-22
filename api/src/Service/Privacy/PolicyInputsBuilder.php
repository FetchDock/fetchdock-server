<?php

namespace App\Service\Privacy;

use App\Entity\PrivacyPolicySettings;
use App\Entity\SiteSetting;
use App\Model\PrivacyPolicyAnswers;
use App\Service\Settings\EffectiveSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects the inputs of the generated privacy policy (ADR 0013): the data inventory, the runtime configuration and
 * the operator's answers.
 */
class PolicyInputsBuilder
{
    /** A user with the view-all role counts as present while they signed in within this period */
    public const string VIEW_ALL_ROLE_WINDOW = '-90 days';

    /** Order of the data categories in the policy */
    public const array CATEGORIES = ['account', 'settings', 'request', 'content', 'device', 'report', 'technical'];

    /** @var array<string, mixed>|null */
    private ?array $inventoryFile = null;

    public function __construct(
        private readonly PolicyTextVersion $textVersion,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
        #[Autowire('%app.failure_reports.enabled%')]
        private readonly bool $failureReportsEnabled,
        #[Autowire('%app.integrity.check_online%')]
        private readonly bool $integrityCheckOnline,
    ) {
    }

    /**
     * @param PrivacyPolicyAnswers|null $answers answers to use instead of the saved ones (preview)
     */
    public function build(PrivacyPolicySettings $settings, ?PrivacyPolicyAnswers $answers = null, \DateTimeImmutable $now = new \DateTimeImmutable()): PolicyInputs
    {
        $configuration = $this->configuration($settings, $now);

        return new PolicyInputs(
            textVersion: $this->textVersion->version(),
            textDigest: $this->textVersion->inputsDigest(),
            appVersion: $this->appVersion,
            answers: $answers ?? $settings->getAnswers(),
            configuration: $configuration,
            inventory: $this->inventory($configuration),
        );
    }

    /**
     * The runtime configuration the text depends on. Every value here changes the hash, so keep it to what the
     * template uses.
     *
     * @return array<string, mixed>
     */
    private function configuration(PrivacyPolicySettings $settings, \DateTimeImmutable $now): array
    {
        $viewAllRoleSeenAt = $settings->getViewAllRoleSeenAt();

        return [
            'failureReports' => [
                'enabled' => $this->failureReportsEnabled,
                // Forwarding to the FetchDock collector (phase 2 of ADR 0012) doesn't exist yet. When it does:
                // ['collectorPolicyVersion' => string] while forwarding is on, null otherwise.
                'forwarding' => null,
            ],
            'siteDefaults' => [
                'sendCookies' => EffectiveSettingsResolver::DEFAULT_SEND_COOKIES,
                'sendUserAgent' => EffectiveSettingsResolver::DEFAULT_SEND_USER_AGENT,
                'sendReferrer' => EffectiveSettingsResolver::DEFAULT_SEND_REFERRER,
            ],
            'siteExceptions' => $this->siteExceptions(),
            'viewAllRoleHolders' => null !== $viewAllRoleSeenAt && $viewAllRoleSeenAt >= $now->modify(self::VIEW_ALL_ROLE_WINDOW),
            'integrityCheckOnline' => $this->integrityCheckOnline,
        ];
    }

    /**
     * Enabled sites whose administrator defaults differ from the built-in ones, by name.
     *
     * @return list<array{site: string, sendCookies: ?bool, sendUserAgent: ?bool, sendReferrer: ?bool}>
     */
    private function siteExceptions(): array
    {
        /** @var list<array{name: string, sendCookies: ?bool, sendUserAgent: ?bool, sendReferrer: ?bool}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('site.name, setting.sendCookies, setting.sendUserAgent, setting.sendReferrer')
            ->from(SiteSetting::class, 'setting')
            ->join('setting.site', 'site')
            ->andWhere('site.enabled = true')
            ->orderBy('site.name')
            ->addOrderBy('site.id')
            ->getQuery()
            ->getArrayResult();

        $defaults = [
            'sendCookies' => EffectiveSettingsResolver::DEFAULT_SEND_COOKIES,
            'sendUserAgent' => EffectiveSettingsResolver::DEFAULT_SEND_USER_AGENT,
            'sendReferrer' => EffectiveSettingsResolver::DEFAULT_SEND_REFERRER,
        ];
        $exceptions = [];
        foreach ($rows as $row) {
            $exception = ['site' => (string) $row['name']];
            $differs = false;
            foreach ($defaults as $key => $default) {
                $value = null === $row[$key] || $row[$key] === $default ? null : (bool) $row[$key];
                $exception[$key] = $value;
                $differs = $differs || null !== $value;
            }
            if ($differs) {
                $exceptions[] = $exception;
            }
        }

        return $exceptions;
    }

    /**
     * The inventory prepared for the template: personal data per category and entity, data stored outside the
     * database, and outgoing connections.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    private function inventory(array $configuration): array
    {
        $inventory = $this->inventoryFile ??= Yaml::parseFile($this->projectDir.'/'.PolicyTextVersion::INVENTORY_FILE);

        $categories = array_fill_keys(self::CATEGORIES, []);
        foreach ($inventory['entities'] ?? [] as $entity) {
            if ($entity['derived'] ?? false) {
                continue;
            }
            $defaults = $entity['defaults'] ?? [];
            $groups = [];
            foreach ($entity['fields'] ?? [] as $field) {
                $classification = array_merge($defaults, $field ?? []);
                if (!($classification['personal'] ?? false)) {
                    continue;
                }
                $category = $classification['category'];
                $groups[$category] ??= ['summary' => $entity['summary'], 'purposes' => [], 'retentions' => [], 'visibleTo' => []];
                $groups[$category]['purposes'][] = $classification['purpose'];
                $groups[$category]['retentions'][] = $classification['retention'];
                array_push($groups[$category]['visibleTo'], ...$classification['visibleTo']);
            }
            foreach ($groups as $category => $group) {
                $categories[$category][] = [
                    'summary' => $group['summary'],
                    'purposes' => array_values(array_unique($group['purposes'])),
                    'retentions' => array_values(array_unique($group['retentions'])),
                    'audiences' => $this->audiences($group['visibleTo'], $configuration['viewAllRoleHolders']),
                ];
            }
        }

        $storage = array_values(array_filter(
            $inventory['storage'] ?? [],
            static fn (array $entry): bool => (bool) ($entry['personal'] ?? false),
        ));
        $connections = array_values(array_filter(
            $inventory['outgoingConnections'] ?? [],
            static fn (array $entry): bool => (bool) ($entry['personal'] ?? false),
        ));

        return [
            'categories' => array_filter($categories),
            'storage' => array_map(static fn (array $entry): array => $entry + ['notes' => null], $storage),
            'connections' => $connections,
        ];
    }

    /**
     * Who can see the data through the app: owner (the user), admins, view_all (non-admin users with the view-all
     * role, only while there are any) or nobody (the server only). Administrators hold the view-all role too.
     *
     * @param list<string> $visibleTo
     *
     * @return list<string>
     */
    private function audiences(array $visibleTo, bool $viewAllRoleHolders): array
    {
        $audiences = [];
        if (\in_array('owner', $visibleTo, true)) {
            $audiences[] = 'owner';
        }
        if (\in_array('admins', $visibleTo, true) || \in_array('view_all', $visibleTo, true)) {
            $audiences[] = 'admins';
        }
        if ($viewAllRoleHolders && \in_array('view_all', $visibleTo, true)) {
            $audiences[] = 'view_all';
        }

        return [] === $audiences ? ['nobody'] : $audiences;
    }
}
