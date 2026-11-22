<?php

namespace App\Command;

use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Enum\PrivacyPolicyStatus;
use App\Enum\PrivacyPolicyUnavailableReason;
use App\Service\Integrity\IntegrityChecker;
use App\Service\Privacy\PrivacyPolicyPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Checks the container's files against the image's integrity manifest and records the result (ADR 0013). Runs in
 * the api and worker entrypoints and daily in the workers (Symfony Scheduler). A modified instance still runs; the
 * status only decides whether the generated privacy policy is published. Also logs when the generated privacy policy
 * needs operator review and approval. Exits 0 unless the check itself fails.
 */
#[AsCommand(name: 'app:integrity:check', description: 'Check the application files against the integrity manifest')]
#[AsPeriodicTask(frequency: '1 day', jitter: 3600, arguments: '--role=worker')]
final readonly class IntegrityCheckCommand
{
    public function __construct(
        private IntegrityChecker $checker,
        private PrivacyPolicyPublisher $privacyPolicyPublisher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Container role (api or worker), defaults to NODE_MODE (worker, else api)')]
        ?string $role = null,
    ): int {
        $integrityRole = null === $role ? IntegrityRole::fromNodeMode(getenv('NODE_MODE') ?: null) : IntegrityRole::tryFrom($role);
        if (null === $integrityRole) {
            $io->error('--role must be api or worker');

            return Command::INVALID;
        }

        $check = $this->checker->check($integrityRole);
        $line = \sprintf('[integrity] %s (FetchDock %s, manifest %s)', $check->getStatus()->value, $check->getAppVersion(), $check->getManifestDigest() ?? 'none');
        if (IntegrityStatus::MODIFIED === $check->getStatus()) {
            $io->warning($line);
            $io->listing($check->getMismatches());
        } else {
            $io->writeln($line);
        }

        [$status, $reason] = $this->privacyPolicyPublisher->resolve();
        if (PrivacyPolicyStatus::GENERATED === $status && $this->privacyPolicyPublisher->isOutdated($status)) {
            $pending = $this->privacyPolicyPublisher->pendingUpdate();
            $current = $this->privacyPolicyPublisher->currentRevision($status);
            $io->warning(\sprintf(
                '[privacy] Action required: published privacy policy (revision %d, policy text %s) does not reflect the latest FetchDock policy text (%s) or configuration (%s). Review the changes and publish an updated revision in Admin → Privacy policy.',
                $current?->getNumber() ?? 0,
                $pending->publishedTextVersion ?? 'unknown',
                $pending->currentTextVersion,
                implode(', ', $pending->reasons()),
            ));
        } elseif (PrivacyPolicyUnavailableReason::NOT_PUBLISHED === $reason) {
            $io->warning('[privacy] Action required: privacy policy answers are complete, but the policy has not been reviewed and published yet in Admin → Privacy policy.');
        }

        return Command::SUCCESS;
    }
}
