<?php

namespace App\Tests\Integration\Integrity;

use App\Entity\InstanceIntegrityCheck;
use App\Entity\PrivacyPolicyRevision;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Enum\PrivacyPolicyRevisionReason;
use App\Model\PrivacyPolicyAnswers;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Service\Integrity\IntegrityManifest;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntegrityCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->application = new Application(self::$kernel);
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testManifestCommandWritesTheManifestAndPrintsItsDigest(): void
    {
        $output = sys_get_temp_dir().'/integrity-manifest-'.bin2hex(random_bytes(4)).'.json';
        $tester = new CommandTester($this->application->find('app:integrity:manifest'));

        try {
            self::assertSame(Command::SUCCESS, $tester->execute(['--git-sha' => 'abc123', '--app-version' => '0.9.0', '--output' => $output]));
            $document = json_decode((string) file_get_contents($output), true);
            self::assertSame('0.9.0', $document['version']);
            self::assertSame('abc123', $document['gitSha']);
            self::assertArrayHasKey('src/Kernel.php', $document['files']);
            self::assertArrayHasKey('composer.lock', $document['files']);
            self::assertSame(hash_file('sha256', $output).'  '.IntegrityManifest::MANIFEST_FILE, trim($tester->getDisplay()));
        } finally {
            @unlink($output);
        }
    }

    public function testCheckCommandRecordsTheResult(): void
    {
        // The test environment has no manifest (like dev images): unknown, without asking GitHub
        $tester = new CommandTester($this->application->find('app:integrity:check'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--role' => 'worker']));
        self::assertStringContainsString('[integrity] unknown', $tester->getDisplay());

        $check = $this->entityManager->getRepository(InstanceIntegrityCheck::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame(IntegrityRole::WORKER, $check->getRole());
        self::assertSame(IntegrityStatus::UNKNOWN, $check->getStatus());
        self::assertSame(gethostname(), $check->getHost());
    }

    public function testCheckCommandRejectsUnknownRoles(): void
    {
        $tester = new CommandTester($this->application->find('app:integrity:check'));

        self::assertSame(Command::INVALID, $tester->execute(['--role' => 'pwa']));
    }

    public function testCheckCommandWarnsWhenPublishedPolicyIsOutdated(): void
    {
        // Note: in test env no manifest exists, so check() records UNKNOWN; verify no false warning while unknown
        $settings = self::getContainer()->get(PrivacyPolicySettingsRepository::class)->get();
        $settings->setAnswers(PrivacyPolicyAnswers::fromArray(PrivacyPolicyAnswersTest::complete()));
        $this->entityManager->persist(new PrivacyPolicyRevision(1, PrivacyPolicyRevisionReason::INITIAL, '0.3', '0.9.0', 'a', 'b', 'c', '# Policy', '<h1>Policy</h1>'));
        $this->entityManager->flush();

        $tester = new CommandTester($this->application->find('app:integrity:check'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--role' => 'worker']));
        self::assertStringNotContainsString('[privacy] Action required:', $tester->getDisplay());
    }
}
