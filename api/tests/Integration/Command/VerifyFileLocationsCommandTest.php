<?php

namespace App\Tests\Integration\Command;

use App\Entity\DownloadDirectory;
use App\Entity\DownloadedFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class VerifyFileLocationsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;
    private string $directoryPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->beginTransaction();
        $this->commandTester = new CommandTester((new Application(self::$kernel))->find('app:files:verify-locations'));
        $this->directoryPath = sys_get_temp_dir().'/verify-locations-'.bin2hex(random_bytes(4));
        mkdir($this->directoryPath);
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        rmdir($this->directoryPath);
        parent::tearDown();
    }

    public function testReportsAndRemapsLegacyAbsolutePaths(): void
    {
        $default = $this->entityManager->getRepository(DownloadDirectory::class)->findOneBy(['isDefault' => true]);
        $legacy = new DownloadedFile()->setLocation($default, $this->directoryPath.'/video.mp4')->setVisible(true);
        $outside = new DownloadedFile()->setLocation($default, '/nowhere-'.bin2hex(random_bytes(4)).'/video.mp4')->setVisible(true);
        $this->entityManager->persist($legacy);
        $this->entityManager->persist($outside);
        $this->entityManager->flush();

        $this->assertSame(Command::FAILURE, $this->commandTester->execute([]));
        $this->assertStringContainsString($this->directoryPath.'/video.mp4', $this->commandTester->getDisplay());

        // Once a directory contains the path, --fix moves the row into it
        $directory = new DownloadDirectory('Verify '.basename($this->directoryPath), $this->directoryPath);
        $this->entityManager->persist($directory);
        $this->entityManager->flush();
        $this->commandTester->execute(['--fix' => true]);

        $this->assertStringContainsString('outside every directory', $this->commandTester->getDisplay());
        $this->entityManager->clear();
        $legacy = $this->entityManager->find(DownloadedFile::class, $legacy->getId());
        $this->assertSame($directory->getId(), $legacy->getDirectory()->getId());
        $this->assertSame('video.mp4', $legacy->getRelativePath());
        $this->assertSame($this->directoryPath.'/video.mp4', $legacy->getPath());
    }

    public function testReportsDirectoriesOutsideTheRoots(): void
    {
        $directory = new DownloadDirectory('Verify '.basename($this->directoryPath), $this->directoryPath);
        $this->entityManager->persist($directory);
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->assertMatchesRegularExpression('/'.preg_quote(basename($this->directoryPath), '/').'.*outside DOWNLOAD_ROOTS/', $this->commandTester->getDisplay());
    }
}
