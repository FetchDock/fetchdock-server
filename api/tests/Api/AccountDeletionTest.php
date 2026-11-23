<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\DownloadedFile;
use App\Entity\DownloadJob;
use App\Entity\DownloadJobEvent;
use App\Entity\OidcSubjectIdentifier;
use App\Entity\UserSetting;
use App\Enum\DownloadStateEnum;
use App\Handler\PurgeFilesHandler;
use App\Message\PurgeFilesMessage;
use App\Tests\Double\DownloadLocations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\Debug\TraceableHub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * DELETE /users/me deletes the user's account and data, and purges the files no other user's job uses (server#129).
 */
class AccountDeletionTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testDeletesTheAccountItsDataAndUnsharedFiles(): void
    {
        $client = static::createClient();
        $owner = $this->createLeaver();
        $uuid = $owner->getUuid()->toRfc4122();
        [$jobId, $ownFileId, $ownPath] = $this->createJobWithFile($owner, softDeleted: true);
        [, $sharedFileId, $sharedPath] = $this->createJobWithFile($owner);
        $this->linkFileToNewJob($sharedFileId, 'user');

        $client->request('DELETE', '/users/me?confirm=leaver', ['headers' => $this->auth('leaver')]);
        $this->assertResponseStatusCodeSame(204);

        $connection = $this->entityManager()->getConnection();
        $this->assertFalse($connection->fetchOne('SELECT 1 FROM oidc_subject_identifier WHERE subject = ?', ['leaver']));
        $this->assertFalse($connection->fetchOne('SELECT 1 FROM download_job WHERE id = ?', [$jobId]), 'Soft-deleted jobs are deleted too');
        $this->assertFalse($connection->fetchOne('SELECT 1 FROM download_job_event WHERE download_job_id = ?', [$jobId]));
        $this->assertFalse($connection->fetchOne('SELECT 1 FROM user_setting WHERE owner_id = ?', [$owner->getId()]));

        // Other clients of the user are told to sign out
        $updates = array_map(static fn (array $message): Update => $message['object'], $this->hub()->getMessages());
        $accountUpdates = array_values(array_filter($updates, static fn (Update $update): bool => ['/users/'.$uuid.'/account'] === $update->getTopics()));
        $this->assertCount(1, $accountUpdates);
        $this->assertTrue($accountUpdates[0]->isPrivate());
        $this->assertSame(['type' => 'account_deleted'], json_decode($accountUpdates[0]->getData(), true));

        // The files are purged asynchronously
        $sent = $this->transport()->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(PurgeFilesMessage::class, $message);
        $this->assertEqualsCanonicalizing([$ownFileId, $sharedFileId], $message->fileIds);

        static::getContainer()->get(PurgeFilesHandler::class)($message);

        $this->assertFileDoesNotExist($ownPath);
        $this->assertFalse($connection->fetchOne('SELECT 1 FROM downloaded_file WHERE id = ?', [$ownFileId]));
        // Another user's job still uses this one
        $this->assertFileExists($sharedPath);
        $this->assertSame(1, (int) $connection->fetchOne('SELECT 1 FROM downloaded_file WHERE id = ?', [$sharedFileId]));
    }

    public function testRequiresTheSubjectAsConfirmation(): void
    {
        $client = static::createClient();
        $this->createLeaver();

        $client->request('DELETE', '/users/me?confirm=someone-else', ['headers' => $this->auth('leaver')]);
        $this->assertResponseStatusCodeSame(400);

        $client->request('DELETE', '/users/me', ['headers' => $this->auth('leaver')]);
        $this->assertResponseStatusCodeSame(422);

        $this->assertNotFalse($this->entityManager()->getConnection()->fetchOne('SELECT 1 FROM oidc_subject_identifier WHERE subject = ?', ['leaver']));
    }

    public function testUserWithoutAccountHasNothingToDelete(): void
    {
        static::createClient()->request('DELETE', '/users/me?confirm=viewer', ['headers' => $this->auth('viewer')]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertCount(0, $this->transport()->getSent());
    }

    public function testRequiresAuthentication(): void
    {
        static::createClient()->request('DELETE', '/users/me?confirm=leaver');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testPurgingAnUntrackedPathKeepsFilesFromBeforeTheJob(): void
    {
        static::createClient();
        $path = $this->temporaryFile();
        DownloadLocations::directory($this->entityManager(), \dirname($path));
        $handler = static::getContainer()->get(PurgeFilesHandler::class);

        // An untracked file that changed before the job was created (filectime() can't be set back, so the job is "later")
        $handler(new PurgeFilesMessage(paths: [$path], writtenSince: time() + 60));
        $this->assertFileExists($path);

        // Without a time, untracked files are never removed
        $handler(new PurgeFilesMessage(paths: [$path]));
        $this->assertFileExists($path);

        // Written by the job
        $handler(new PurgeFilesMessage(paths: [$path], writtenSince: time() - 60));
        $this->assertFileDoesNotExist($path);
    }

    private function createLeaver(): OidcSubjectIdentifier
    {
        $entityManager = $this->entityManager();
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => 'leaver']);
        if (null === $owner) {
            $owner = new OidcSubjectIdentifier()->setSubject('leaver');
            $entityManager->persist($owner);
            $entityManager->persist(new UserSetting()->setOwner($owner));
            $entityManager->flush();
        }

        return $owner;
    }

    /**
     * @return array{int, int, string} the job ID, the file ID and the file's path
     */
    private function createJobWithFile(OidcSubjectIdentifier $owner, bool $softDeleted = false): array
    {
        $entityManager = $this->entityManager();
        $owner = $entityManager->find(OidcSubjectIdentifier::class, $owner->getId());
        $path = $this->temporaryFile();

        $job = new DownloadJob()
            ->setUri('https://example.com/account-deletion-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::COMPLETED)
            ->setOwner($owner);
        $file = DownloadLocations::persistedDirectoryFile($entityManager, $path)->setVisible(true);
        $job->addFile($file);
        $event = new DownloadJobEvent()->setDownloadJob($job)->setEvent('test')->setSource('test');

        $entityManager->persist($job);
        $entityManager->persist($file);
        $entityManager->persist($event);
        $entityManager->flush();
        if ($softDeleted) {
            $entityManager->remove($job);
            $entityManager->flush();
        }

        return [$job->getId(), $file->getId(), $path];
    }

    private function linkFileToNewJob(int $fileId, string $ownerSubject): void
    {
        $entityManager = $this->entityManager();
        $job = new DownloadJob()
            ->setUri('https://example.com/account-deletion-other-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState(DownloadStateEnum::ALREADY_EXISTS)
            ->setOwner($entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $ownerSubject]));
        $job->addFile($entityManager->find(DownloadedFile::class, $fileId));

        $entityManager->persist($job);
        $entityManager->flush();
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'account-deletion-');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function hub(): TraceableHub
    {
        $hub = static::getContainer()->get(HubInterface::class);
        $this->assertInstanceOf(TraceableHub::class, $hub);

        return $hub;
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }

    /**
     * @return array{Authorization: string}
     */
    private function auth(string $user): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass')];
    }
}
