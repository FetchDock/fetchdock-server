<?php

namespace App\State\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Resource\Admin\AdminInstanceIntegrity;
use App\Resource\Admin\AdminInstanceIntegrityCheck;
use App\Service\Integrity\IntegrityReport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GET /admin/instance_integrity.
 *
 * @implements ProviderInterface<AdminInstanceIntegrity>
 */
final readonly class AdminInstanceIntegrityProvider implements ProviderInterface
{
    public function __construct(
        private IntegrityReport $report,
        #[Autowire('%app.version%')]
        private string $appVersion,
        #[Autowire('%app.integrity.check_online%')]
        private bool $checkOnline,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminInstanceIntegrity
    {
        $now = new \DateTimeImmutable();
        $summary = $this->report->summary($now);

        $resource = new AdminInstanceIntegrity();
        $resource->status = $summary->status;
        $resource->appVersion = $this->appVersion;
        $resource->manifestDigest = $summary->manifestDigest;
        $resource->gitSha = $summary->gitSha;
        $resource->checkOnline = $this->checkOnline;
        $resource->checks = array_map(AdminInstanceIntegrityCheck::fromEntity(...), $this->report->latestChecks($now));

        return $resource;
    }
}
