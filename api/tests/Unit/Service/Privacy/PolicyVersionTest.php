<?php

namespace App\Tests\Unit\Service\Privacy;

use App\Service\Privacy\PolicyTextVersion;
use PHPUnit\Framework\TestCase;

/**
 * Every change to the inputs of the privacy policy text needs a new text version (ADR 0013).
 */
final class PolicyVersionTest extends TestCase
{
    private PolicyTextVersion $policyVersion;

    protected function setUp(): void
    {
        $this->policyVersion = new PolicyTextVersion(\dirname(__DIR__, 4));
    }

    public function testTheLatestVersionMatchesTheCurrentInputs(): void
    {
        $versions = $this->policyVersion->versions();
        $latest = $versions[array_key_last($versions)];
        $digest = $this->policyVersion->inputsDigest();

        self::assertSame($digest, $latest['digest'], \sprintf(
            "The privacy policy inputs (%s) changed. Append a new version to %s instead of editing an old one:\n\n"
            ."    - { version: '<next>', digest: %s, changes: '<what changed in the text>' }\n",
            implode(', ', $this->policyVersion->inputFiles()),
            PolicyTextVersion::VERSION_FILE,
            $digest,
        ));
    }

    public function testVersionsAreNumberedAndNeverReused(): void
    {
        $versions = $this->policyVersion->versions();
        $previous = null;
        $digests = [];
        foreach ($versions as $entry) {
            self::assertMatchesRegularExpression('/^\d+\.\d+$/', $entry['version'], 'Versions are MAJOR.MINOR');
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['digest']);
            self::assertNotSame('', trim($entry['changes']), \sprintf('Version %s describes its changes', $entry['version']));
            if (null !== $previous) {
                self::assertTrue(version_compare($entry['version'], $previous, '>'), \sprintf('Version %s must be greater than %s', $entry['version'], $previous));
            }
            self::assertNotContains($entry['digest'], $digests, \sprintf('Version %s repeats an older digest', $entry['version']));
            $digests[] = $entry['digest'];
            $previous = $entry['version'];
        }
    }
}
