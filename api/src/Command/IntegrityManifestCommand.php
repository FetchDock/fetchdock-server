<?php

namespace App\Command;

use App\Service\Integrity\IntegrityManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes integrity-manifest.json (ADR 0013). The last step of the prod image build (api/Dockerfile); CI publishes
 * the printed digest as <APP_VERSION>.sha256 on the "integrity-digests" pre-release.
 */
#[AsCommand(name: 'app:integrity:manifest', description: 'Write the integrity manifest of the application files')]
final readonly class IntegrityManifestCommand
{
    public function __construct(
        private IntegrityManifest $manifest,
        #[Autowire('%app.version%')]
        private string $appVersion,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Commit the image is built from')]
        string $gitSha = '',
        #[Option(description: 'App version, defaults to APP_VERSION')]
        ?string $appVersion = null,
        #[Option(description: 'Output file, defaults to integrity-manifest.json in the project directory')]
        ?string $output = null,
    ): int {
        $content = $this->manifest->build($appVersion ?? $this->appVersion, $gitSha);
        $output ??= $this->manifest->path();
        if (false === file_put_contents($output, $content)) {
            $io->error(\sprintf('Cannot write %s', $output));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('%s  %s', hash('sha256', $content), IntegrityManifest::MANIFEST_FILE));

        return Command::SUCCESS;
    }
}
