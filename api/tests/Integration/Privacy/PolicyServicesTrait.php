<?php

namespace App\Tests\Integration\Privacy;

use App\Repository\PrivacyPolicyRevisionRepository;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Privacy\PolicyInputsBuilder;
use App\Service\Privacy\PolicyRenderer;
use App\Service\Privacy\PolicyTextVersion;
use App\Service\Privacy\PrivacyPolicyRevisions;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * Builds the privacy policy services with a given configuration. They have no consumers yet (the /privacy and admin
 * endpoints will), so the container removed them.
 */
trait PolicyServicesTrait
{
    private function inputsBuilder(string $appVersion = '0.9.0', bool $failureReports = true, bool $integrityOnline = true): PolicyInputsBuilder
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        return new PolicyInputsBuilder(
            new PolicyTextVersion($projectDir),
            self::getContainer()->get(EntityManagerInterface::class),
            $projectDir,
            $appVersion,
            $failureReports,
            $integrityOnline,
        );
    }

    private function renderer(): PolicyRenderer
    {
        return new PolicyRenderer(self::getContainer()->get(Environment::class));
    }

    private function revisions(?PolicyInputsBuilder $inputsBuilder = null, ?PolicyTextVersion $textVersion = null): PrivacyPolicyRevisions
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        return new PrivacyPolicyRevisions(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(PrivacyPolicySettingsRepository::class),
            self::getContainer()->get(PrivacyPolicyRevisionRepository::class),
            $inputsBuilder ?? $this->inputsBuilder(),
            $this->renderer(),
            $textVersion ?? new PolicyTextVersion($projectDir),
        );
    }
}
