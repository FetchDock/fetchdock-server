<?php

namespace App\Command;

use App\Service\Privacy\PolicyReleaseNotes;
use App\Service\Privacy\PolicyTextVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Prints the "Data practices changed" section of the release notes (ADR 0013) from the policy text versions added
 * since the previous release. Run through `make privacy-release-notes SINCE=<tag>`, which pipes the previous release's
 * policy-version.yaml in.
 */
#[AsCommand(name: 'app:privacy:release-notes', description: 'Print the "Data practices changed" release-notes section')]
final readonly class PrivacyReleaseNotesCommand
{
    public function __construct(
        private PolicyTextVersion $policyVersion,
        private PolicyReleaseNotes $releaseNotes,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        #[Option(description: 'privacy/policy-version.yaml of the previous release, or "-" for stdin (empty when the previous release had none)')]
        ?string $previous = null,
    ): int {
        $stderr = $io->getErrorStyle();
        if (null === $previous || '' === $previous) {
            $stderr->error('Pass --previous=<file> (or "-" for stdin) with the previous release\'s policy-version.yaml, or run `make privacy-release-notes SINCE=<tag>`.');

            return Command::INVALID;
        }

        if ('-' === $previous) {
            $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
            $content = stream_get_contents($stream);
        } else {
            $content = @file_get_contents($previous);
        }

        if (false === $content) {
            $stderr->error(\sprintf('Cannot read %s', $previous));

            return Command::FAILURE;
        }

        try {
            $section = $this->releaseNotes->section(
                '' === trim($content) ? null : PolicyTextVersion::parse($content),
                $this->policyVersion->versions(),
            );
        } catch (\UnexpectedValueException|ParseException $e) {
            $stderr->error($e->getMessage());

            return Command::FAILURE;
        }

        if ('' === $section) {
            $stderr->writeln('No policy text versions were added since the previous release: omit the "Data practices changed" section.');

            return Command::SUCCESS;
        }

        $output->write($section, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
