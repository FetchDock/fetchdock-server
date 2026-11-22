<?php

namespace App\Tests\Integration\Command;

use App\Service\Privacy\PolicyReleaseNotes;
use App\Service\Privacy\PolicyTextVersion;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PrivacyReleaseNotesCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private string $previousFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:privacy:release-notes'));
        $this->previousFile = sys_get_temp_dir().'/policy-version-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->previousFile);
        parent::tearDown();
    }

    public function testPrintsTheVersionsAddedSinceThePreviousRelease(): void
    {
        $versions = self::getContainer()->get(PolicyTextVersion::class)->versions();
        $first = $versions[0];
        file_put_contents($this->previousFile, \sprintf("versions:\n    - { version: '%s', digest: %s, changes: 'First' }\n", $first['version'], $first['digest']));

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));
        $display = $this->tester->getDisplay();
        self::assertStringStartsWith(PolicyReleaseNotes::HEADING, $display);
        self::assertStringNotContainsString('**'.$first['version'].':**', $display);
        $latest = $versions[array_key_last($versions)];
        if ($latest !== $first) {
            self::assertStringContainsString(\sprintf('- **%s:** %s', $latest['version'], $latest['changes']), $display);
        }
    }

    public function testReadsPreviousVersionsFromStdin(): void
    {
        $versions = self::getContainer()->get(PolicyTextVersion::class)->versions();
        $first = $versions[0];
        $this->tester->setInputs([\sprintf("versions:\n    - { version: '%s', digest: %s, changes: 'First' }\n", $first['version'], $first['digest'])]);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--previous' => '-'], ['capture_stderr_separately' => true]));
        self::assertStringStartsWith(PolicyReleaseNotes::HEADING, $this->tester->getDisplay());
    }

    public function testPrintsNothingWhenNoVersionWasAdded(): void
    {
        copy(self::$kernel->getProjectDir().'/'.PolicyTextVersion::VERSION_FILE, $this->previousFile);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));
        self::assertSame('', $this->tester->getDisplay());
        self::assertStringContainsString('No policy text versions were added', $this->tester->getErrorOutput());
    }

    public function testAnEmptyFileMeansThePreviousReleaseHadNoPolicy(): void
    {
        file_put_contents($this->previousFile, '');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('FetchDock can now generate', $this->tester->getDisplay());
    }

    public function testFailsWhenAVersionWasEdited(): void
    {
        file_put_contents($this->previousFile, "versions:\n    - { version: '0.1', digest: 0000, changes: 'Edited' }\n");

        self::assertSame(Command::FAILURE, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));
        self::assertSame('', $this->tester->getDisplay());
        self::assertStringContainsString('was edited or removed', $this->tester->getErrorOutput());
    }

    public function testFailsOnInvalidYamlOrMalformedEntries(): void
    {
        file_put_contents($this->previousFile, "versions: [\n");
        self::assertSame(Command::FAILURE, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));

        file_put_contents($this->previousFile, "versions:\n    - { version: '0.1' }\n");
        self::assertSame(Command::FAILURE, $this->tester->execute(['--previous' => $this->previousFile], ['capture_stderr_separately' => true]));
    }

    public function testRequiresThePreviousFile(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame(Command::FAILURE, $this->tester->execute(['--previous' => $this->previousFile.'.missing'], ['capture_stderr_separately' => true]));
    }
}
