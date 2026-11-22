<?php

namespace App\Tests\Integration\Integrity;

use App\Entity\InstanceIntegrityCheck;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Repository\InstanceIntegrityCheckRepository;
use App\Service\Integrity\IntegrityChecker;
use App\Service\Integrity\IntegrityManifest;
use App\Service\Integrity\OfficialDigestProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The integrity statuses of ADR 0013 against a fake application directory.
 */
final class IntegrityCheckerTest extends KernelTestCase
{
    private const string VERSION = '0.9.0';

    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private IntegrityManifest $manifest;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();

        $this->projectDir = sys_get_temp_dir().'/integrity-'.bin2hex(random_bytes(4));
        $files = [
            'src/Kernel.php' => '<?php // kernel',
            'src/Entity/Job.php' => '<?php // job',
            'config/services.yaml' => 'services: ~',
            'vendor/acme/lib/Lib.php' => '<?php // lib',
            'public/index.php' => '<?php // front controller',
            'composer.lock' => '{}',
            // Not covered
            'var/cache/prod/container.php' => '<?php',
            '.env' => 'APP_ENV=prod',
            'config/gallery-dl/config.json' => '{}',
            'bin/__pycache__/resolve_url.cpython-313.pyc' => 'bytecode',
            'downloads/video.mp4' => 'media',
        ];
        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($this->projectDir.'/'.$path, $content);
        }
        $this->manifest = new IntegrityManifest($this->projectDir);
        file_put_contents($this->manifest->path(), $this->manifest->build(self::VERSION, 'abc123'));
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        new Filesystem()->remove($this->projectDir);
        parent::tearDown();
    }

    public function testManifestCoversTheApplicationFilesOnly(): void
    {
        $document = json_decode((string) file_get_contents($this->manifest->path()), true);

        self::assertSame(self::VERSION, $document['version']);
        self::assertSame('abc123', $document['gitSha']);
        self::assertSame(['composer.lock', 'config/services.yaml', 'public/index.php', 'src/Entity/Job.php', 'src/Kernel.php', 'vendor/acme/lib/Lib.php'], array_keys($document['files']));
        self::assertSame(hash('sha256', '<?php // kernel'), $document['files']['src/Kernel.php']);
    }

    public function testVerifiedWhenFilesAndOfficialDigestMatch(): void
    {
        // Runtime changes outside the covered files don't matter
        file_put_contents($this->projectDir.'/var/cache/prod/container.php', '<?php // rebuilt');
        file_put_contents($this->projectDir.'/config/gallery-dl/config.json', '{"extractor": {}}');
        file_put_contents($this->projectDir.'/.env.local.php', '<?php return [];');

        $check = $this->checker(official: $this->digest())->check(IntegrityRole::API, 'api-1');

        self::assertSame(IntegrityStatus::VERIFIED, $check->getStatus());
        self::assertSame($this->digest(), $check->getManifestDigest());
        self::assertSame(self::VERSION, $check->getAppVersion());
        self::assertSame([], $check->getMismatches());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(InstanceIntegrityCheck::class, $check->getId());
        self::assertSame('api-1', $stored->getHost());
        self::assertSame(IntegrityRole::API, $stored->getRole());
        self::assertSame(IntegrityStatus::VERIFIED, $stored->getStatus());
    }

    public function testModifiedFileIsReported(): void
    {
        file_put_contents($this->projectDir.'/src/Kernel.php', '<?php // patched');
        file_put_contents($this->projectDir.'/src/Backdoor.php', '<?php // added');
        unlink($this->projectDir.'/config/services.yaml');

        $check = $this->checker(official: $this->digest())->check(IntegrityRole::WORKER, 'worker-1');

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
        self::assertSame(['config/services.yaml', 'src/Backdoor.php', 'src/Kernel.php'], $check->getMismatches());
        self::assertSame(IntegrityRole::WORKER, $check->getRole());
    }

    public function testModifiedFilesAreCappedAtFifty(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            file_put_contents(\sprintf('%s/src/Added%02d.php', $this->projectDir, $i), '<?php');
        }

        $check = $this->checker(official: $this->digest())->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
        self::assertCount(InstanceIntegrityCheck::MAX_MISMATCHES, $check->getMismatches());
    }

    public function testForeignManifestIsModified(): void
    {
        // The files match the manifest, but the manifest isn't the one CI published for this version
        $check = $this->checker(official: str_repeat('0', 64))->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
        self::assertSame([IntegrityManifest::MANIFEST_FILE], $check->getMismatches());
    }

    public function testManifestOfAnotherVersionIsModified(): void
    {
        $check = $this->checker(official: $this->digest(), version: '0.9.1')->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
        self::assertSame([IntegrityManifest::MANIFEST_FILE], $check->getMismatches());
    }

    public function testUnreadableManifestIsModified(): void
    {
        file_put_contents($this->manifest->path(), 'not json');

        $check = $this->checker(official: $this->digest())->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
    }

    public function testUnknownWhenTheOfficialDigestCannotBeFetched(): void
    {
        $check = $this->checker(official: null)->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::UNKNOWN, $check->getStatus());
        self::assertSame($this->digest(), $check->getManifestDigest());
    }

    public function testUnknownOfflineWithoutAskingGitHub(): void
    {
        $officialDigests = $this->createMock(OfficialDigestProvider::class);
        $officialDigests->expects(self::never())->method('fetch');

        $check = $this->checker($officialDigests, online: false)->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::UNKNOWN, $check->getStatus());
    }

    public function testOfflineStillReportsModifiedFiles(): void
    {
        file_put_contents($this->projectDir.'/vendor/acme/lib/Lib.php', '<?php // patched');

        $check = $this->checker(official: null, online: false)->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::MODIFIED, $check->getStatus());
        self::assertSame(['vendor/acme/lib/Lib.php'], $check->getMismatches());
    }

    public function testUnknownWithoutManifest(): void
    {
        $checker = $this->checker(official: $this->digest());
        unlink($this->manifest->path());

        $check = $checker->check(IntegrityRole::API);

        self::assertSame(IntegrityStatus::UNKNOWN, $check->getStatus());
        self::assertNull($check->getManifestDigest());
    }

    public function testOldChecksAreDeleted(): void
    {
        $old = new InstanceIntegrityCheck('api-0', IntegrityRole::API, IntegrityStatus::MODIFIED, null, self::VERSION, [], new \DateTimeImmutable('-31 days'));
        $this->entityManager->persist($old);
        $this->entityManager->flush();
        $oldId = $old->getId();

        $this->checker(official: $this->digest())->check(IntegrityRole::API);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(InstanceIntegrityCheck::class, $oldId));
    }

    private function digest(): string
    {
        return hash_file('sha256', $this->manifest->path());
    }

    private function checker(?OfficialDigestProvider $officialDigests = null, ?string $official = null, bool $online = true, string $version = self::VERSION): IntegrityChecker
    {
        if (null === $officialDigests) {
            $officialDigests = $this->createStub(OfficialDigestProvider::class);
            $officialDigests->method('fetch')->willReturn($official);
        }

        return new IntegrityChecker(
            $this->manifest,
            $officialDigests,
            self::getContainer()->get(InstanceIntegrityCheckRepository::class),
            $this->entityManager,
            new NullLogger(),
            $version,
            $online,
        );
    }
}
