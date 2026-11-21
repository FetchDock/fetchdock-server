<?php

namespace App\Tests\Unit\Mercure;

use App\Entity\DownloadJob;
use App\Entity\OidcSubjectIdentifier;
use App\Mercure\UserTopics;
use PHPUnit\Framework\TestCase;

class UserTopicsTest extends TestCase
{
    public function testTopicsAreUnderTheOwnersPrefix(): void
    {
        $owner = new OidcSubjectIdentifier()->setSubject('user');
        $job = new DownloadJob()->setUri('https://example.com/video')->setOwner($owner);
        $prefix = '/users/'.$owner->getUuid()->toRfc4122();

        $this->assertSame($prefix, UserTopics::prefix($owner));
        $this->assertSame($prefix.'/{+path}', UserTopics::subscribeSelector($owner));
        $this->assertSame($prefix.'/download_jobs/'.$job->getUuid()->toRfc4122(), UserTopics::downloadJob($job));
        $this->assertSame($prefix.'/settings', UserTopics::settings($owner));
        $this->assertSame($prefix.'/download_jobs/'.$job->getUuid()->toRfc4122().'/output', UserTopics::downloadJobOutput($job));
    }

    public function testUsersHaveDifferentPrefixes(): void
    {
        $this->assertNotSame(
            UserTopics::prefix(new OidcSubjectIdentifier()->setSubject('a')),
            UserTopics::prefix(new OidcSubjectIdentifier()->setSubject('b'))
        );
    }

    public function testJobWithoutOwnerHasNoTopics(): void
    {
        $job = new DownloadJob()->setUri('https://example.com/video');

        $this->assertNull(UserTopics::downloadJob($job));
        $this->assertNull(UserTopics::downloadJobOutput($job));
    }

    public function testDownloadJobMercureOptionsArePrivateToTheOwner(): void
    {
        $owner = new OidcSubjectIdentifier()->setSubject('user');
        $job = new DownloadJob()->setUri('https://example.com/video')->setOwner($owner);

        $this->assertSame(['private' => true, 'topics' => [UserTopics::downloadJob($job)]], $job->mercureOptions());
    }

    public function testDownloadJobWithoutOwnerHasNoMercureUpdates(): void
    {
        $this->assertFalse(new DownloadJob()->setUri('https://example.com/video')->mercureOptions());
    }
}
