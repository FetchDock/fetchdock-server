<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client as HttpClient;
use App\Entity\DownloadJob;
use App\Entity\FailureReport;
use App\Entity\OidcSubjectIdentifier;
use App\Enum\DownloadStateEnum;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Failure reports (#119, ADR 0012): users report failed jobs and unsupported URLs; admins triage them without
 * seeing the reporter or the job.
 */
class FailureReportTest extends ApiTestCase
{
    use SiteTestTrait;

    private HttpClient $http;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->http = static::createClient();
        $this->syncSites();
        $this->entityManager()->createQuery(sprintf('DELETE FROM %s r', FailureReport::class))->execute();
    }

    public function testUserReportsAnUnsupportedUrlAndWithdrawsIt(): void
    {
        $report = $this->report([
            'kind' => 'unsupported_url',
            'url' => 'https://Example.com/page?x=1',
            'userAgent' => 'Mozilla/5.0',
            'referrer' => 'https://example.com/',
            'comment' => 'Nothing happens',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('unsupported_url', $report['kind']);
        $this->assertSame('https://Example.com/page?x=1', $report['url'], 'stored as sent');
        $this->assertSame('example.com', $report['host']);
        $this->assertNull($report['site']);
        $this->assertSame('new', $report['status']);
        $this->assertSame('Mozilla/5.0', $report['userAgent']);
        $this->assertSame('https://example.com/', $report['referrer']);
        $this->assertSame('Nothing happens', $report['comment']);
        $this->assertNull($report['downloadJob']);
        $this->assertNull($report['diagnostics']);
        foreach (['owner', 'canonicalKey', 'adminNote', 'updatedAt'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $report);
        }

        $members = $this->http->request('GET', '/users/failure_reports', ['headers' => self::auth()])->toArray()['member'];
        $this->assertSame([$report['id']], array_column($members, 'id'));
        $this->http->request('GET', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()]);
        $this->assertResponseIsSuccessful();

        $this->http->request('DELETE', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->entityManager()->find(FailureReport::class, $report['id']), 'withdrawn reports are deleted for good');
    }

    public function testSiteIsResolvedWithoutProbing(): void
    {
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=abc']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('youtube', $report['site']);
        $this->assertSame('www.youtube.com', $report['host']);
    }

    public function testReportsAreIsolatedPerOwner(): void
    {
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/admin-only'], 'admin');

        $this->assertSame([], $this->http->request('GET', '/users/failure_reports', ['headers' => self::auth()])->toArray()['member']);
        foreach (['GET', 'DELETE'] as $method) {
            $this->http->request($method, '/users/failure_reports/'.$report['id'], ['headers' => self::auth()]);
            $this->assertResponseStatusCodeSame(404);
        }
        $this->http->request('GET', '/users/failure_reports');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUserReportsTheirFailedJob(): void
    {
        $job = $this->createJob('user', DownloadStateEnum::FAILED);

        $report = $this->report([
            'kind' => 'job_failed',
            'url' => $job->getUri(),
            'downloadJob' => '/download_jobs/'.$job->getUuid(),
            'diagnostics' => ['downloader' => 'yt-dlp-cli', 'errorMessage' => 'ERROR: Unsupported URL', 'clientVersion' => '0.8.0'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/download_jobs/'.$job->getUuid(), $report['downloadJob']);
        $this->assertSame(['downloader' => 'yt-dlp-cli', 'downloaderVersion' => null, 'errorMessage' => 'ERROR: Unsupported URL', 'clientVersion' => '0.8.0'], self::withoutJsonLdKeywords($report)['diagnostics']);

        $stored = $this->entityManager()->find(FailureReport::class, $report['id']);
        $this->assertNull($stored->getUserAgent(), 'nothing is copied from the job');
        $this->assertNull($stored->getReferrer());
    }

    public function testDeletedJobsAreUnlinked(): void
    {
        $job = $this->createJob('user', DownloadStateEnum::FAILED);
        $report = $this->report(['kind' => 'job_failed', 'url' => $job->getUri(), 'downloadJob' => '/download_jobs/'.$job->getUuid()]);
        $this->assertResponseStatusCodeSame(201);

        // Soft-deleted by its owner: hidden, the link stays in the database
        $this->http->request('DELETE', '/download_jobs/'.$job->getUuid(), ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->http->request('GET', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()])->toArray()['downloadJob']);

        // Deleted for good: ON DELETE SET NULL
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM download_job_event WHERE download_job_id = ?', [$job->getId()]);
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM download_job WHERE id = ?', [$job->getId()]);
        $this->assertNull($this->entityManager()->find(FailureReport::class, $report['id'])->getDownloadJob());
    }

    #[DataProvider('invalidReports')]
    public function testInvalidReportsAreRejected(callable $body): void
    {
        $this->report($body($this));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->countReports());
    }

    public static function invalidReports(): iterable
    {
        yield 'job_failed without a job' => [fn (): array => ['kind' => 'job_failed', 'url' => 'https://example.com/a']];
        yield "another user's job" => [fn (self $test): array => $test->jobReport($test->createJob('admin', DownloadStateEnum::FAILED))];
        yield 'a job that did not fail' => [fn (self $test): array => $test->jobReport($test->createJob('user', DownloadStateEnum::COMPLETED))];
        yield 'not a job IRI' => [fn (): array => ['kind' => 'job_failed', 'url' => 'https://example.com/a', 'downloadJob' => '/sites/youtube']];
        yield 'unknown job' => [fn (): array => ['kind' => 'job_failed', 'url' => 'https://example.com/a', 'downloadJob' => '/download_jobs/0196f3c0-0000-7000-8000-000000000000']];
        yield 'diagnostics on unsupported_url' => [fn (): array => ['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'diagnostics' => ['downloader' => 'yt-dlp-cli']]];
        yield 'job on unsupported_url' => [fn (self $test): array => ['kind' => 'unsupported_url'] + $test->jobReport($test->createJob('user', DownloadStateEnum::FAILED))];
        yield 'non-http URL' => [fn (): array => ['kind' => 'unsupported_url', 'url' => 'file:///etc/passwd']];
        yield 'non-http referrer' => [fn (): array => ['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'referrer' => 'javascript:alert(1)']];
        yield 'URL too long' => [fn (): array => ['kind' => 'unsupported_url', 'url' => 'https://example.com/'.str_repeat('a', 2048)]];
        yield 'comment too long' => [fn (): array => ['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'comment' => str_repeat('a', 2001)]];
    }

    public function testUnknownFieldsAndKindsAreRejected(): void
    {
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'cookies' => 'session=1']);
        $this->assertResponseStatusCodeSame(400);
        $this->report(['kind' => 'other', 'url' => 'https://example.com/a']);
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame(0, $this->countReports());
    }

    public function testDuplicateReportsAreRejectedWithin24Hours(): void
    {
        $first = $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=dup']);
        $this->assertResponseStatusCodeSame(201);

        // Same media (canonical key) and kind, another spelling
        $this->http->request('POST', '/users/failure_reports', [
            'json' => ['kind' => 'unsupported_url', 'url' => 'https://youtu.be/dup'],
            'headers' => self::auth() + ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
        $this->assertStringEndsWith('/users/failure_reports/'.$first['id'], $this->http->getResponse()->toArray(false)['detail']);

        // Another user, or after 24 hours: accepted
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://youtu.be/dup'], 'admin');
        $this->assertResponseStatusCodeSame(201);
        $this->entityManager()->createQuery(sprintf('UPDATE %s r SET r.createdAt = :old', FailureReport::class))->setParameter('old', new \DateTimeImmutable('-25 hours'))->execute();
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://youtu.be/dup']);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testAdminTriagesReportsWithoutSeeingTheReporterOrTheJob(): void
    {
        $job = $this->createJob('user', DownloadStateEnum::FAILED);
        $report = $this->report($this->jobReport($job) + ['comment' => 'Please fix']);
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=other']);

        $members = $this->http->request('GET', '/admin/failure_reports', ['headers' => self::auth('admin')])->toArray()['member'];
        $this->assertCount(2, $members);
        foreach ($members as $member) {
            foreach (['owner', 'downloadJob', 'canonicalKey'] as $hidden) {
                $this->assertArrayNotHasKey($hidden, $member);
            }
            $this->assertStringNotContainsString((string) $job->getUuid(), json_encode($member));
        }

        $filtered = $this->http->request('GET', '/admin/failure_reports', ['query' => ['site' => 'youtube'], 'headers' => self::auth('admin')])->toArray()['member'];
        $this->assertSame(['unsupported_url'], array_column($filtered, 'kind'));
        $filtered = $this->http->request('GET', '/admin/failure_reports', ['query' => ['kind' => 'job_failed', 'host' => 'example.com'], 'headers' => self::auth('admin')])->toArray()['member'];
        $this->assertSame([$report['id']], array_column($filtered, 'id'));

        $patched = $this->http->request('PATCH', '/admin/failure_reports/'.$report['id'], [
            'json' => ['status' => 'acknowledged', 'adminNote' => 'Known extractor bug'],
            'headers' => self::auth('admin') + ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertSame('acknowledged', $patched['status']);
        $this->assertSame('Known extractor bug', $patched['adminNote']);
        $this->assertSame('Please fix', $patched['comment']);
        $this->assertArrayNotHasKey('downloadJob', $patched);

        $this->http->request('PATCH', '/admin/failure_reports/'.$report['id'], [
            'json' => ['comment' => 'changed'],
            'headers' => self::auth('admin') + ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(400);

        // The reporter sees the status, not the note
        $own = $this->http->request('GET', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()])->toArray();
        $this->assertSame('acknowledged', $own['status']);
        $this->assertArrayNotHasKey('adminNote', $own);

        $item = $this->http->request('GET', '/admin/failure_reports/'.$report['id'], ['headers' => self::auth('admin')])->toArray();
        $this->assertArrayNotHasKey('owner', $item);
        $this->assertArrayNotHasKey('downloadJob', $item);
    }

    public function testSummaryCountsReportsPerHostSiteAndKind(): void
    {
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=1']);
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=2']);
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://www.youtube.com/watch?v=1'], 'admin');
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a']);
        $this->report($this->jobReport($this->createJob('user', DownloadStateEnum::FAILED)));

        $rows = $this->http->request('GET', '/admin/failure_reports/summary', ['headers' => self::auth('admin')])->toArray()['member'];
        $rows = array_map(static fn (array $row): array => [$row['host'], $row['site'], $row['kind'], $row['count']], $rows);
        $this->assertSame([
            ['www.youtube.com', 'youtube', 'unsupported_url', 3],
            ['example.com', null, 'job_failed', 1],
            ['example.com', null, 'unsupported_url', 1],
        ], self::sortTail($rows));

        $jobRows = $this->http->request('GET', '/admin/failure_reports/summary', ['query' => ['kind' => 'job_failed'], 'headers' => self::auth('admin')])->toArray()['member'];
        $this->assertCount(1, $jobRows);
        $future = $this->http->request('GET', '/admin/failure_reports/summary', ['query' => ['since' => new \DateTimeImmutable('+1 hour')->format(\DateTimeInterface::ATOM)], 'headers' => self::auth('admin')])->toArray()['member'];
        $this->assertSame([], $future);
    }

    public function testAdminEndpointsNeedRoleAdmin(): void
    {
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a']);

        foreach (['/admin/failure_reports', '/admin/failure_reports/'.$report['id'], '/admin/failure_reports/summary'] as $path) {
            foreach (['user', 'viewer'] as $user) {
                $this->http->request('GET', $path, ['headers' => self::auth($user)]);
                $this->assertResponseStatusCodeSame(403, $path.' as '.$user);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function report(array $body, string $user = 'user'): array
    {
        return $this->http->request('POST', '/users/failure_reports', [
            'json' => $body,
            'headers' => self::auth($user) + ['Content-Type' => 'application/ld+json'],
        ])->toArray(false);
    }

    /**
     * Rows after the first are ordered by their last report, which is the same second here: compare them sorted.
     */
    private static function sortTail(array $rows): array
    {
        $tail = array_slice($rows, 1);
        usort($tail, static fn (array $a, array $b): int => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        return [$rows[0], ...$tail];
    }

    public function jobReport(DownloadJob $job): array
    {
        return ['kind' => 'job_failed', 'url' => $job->getUri(), 'downloadJob' => '/download_jobs/'.$job->getUuid()];
    }

    public function createJob(string $ownerSubject, DownloadStateEnum $state): DownloadJob
    {
        $entityManager = $this->entityManager();
        $owner = $entityManager->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $ownerSubject]);
        $this->assertNotNull($owner, "Missing test fixture for subject '{$ownerSubject}', run `make prep-test`");

        $job = new DownloadJob()
            ->setUri('https://example.com/report-test-'.bin2hex(random_bytes(6)))
            ->setDownloader('mock')
            ->setState($state)
            ->setOwner($owner);
        $entityManager->persist($job);
        $entityManager->flush();

        return $job;
    }

    private function countReports(): int
    {
        return (int) $this->entityManager()->createQuery(sprintf('SELECT COUNT(r.id) FROM %s r', FailureReport::class))->getSingleScalarResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }
}
