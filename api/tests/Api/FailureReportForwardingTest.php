<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client as HttpClient;
use App\Entity\FailureReport;
use App\Entity\FailureReportForwardingSettings;
use App\Entity\OidcSubjectIdentifier;
use App\EventListener\FailureReportForwardingFailedListener;
use App\Handler\DeleteUpstreamReportHandler;
use App\Handler\ForwardFailureReportHandler;
use App\Handler\ForwardUpstreamCommentHandler;
use App\Message\DeleteUpstreamReportMessage;
use App\Message\ForwardFailureReportMessage;
use App\Message\ForwardUpstreamCommentMessage;
use App\Service\Account\AccountDeleter;
use App\Service\FailureReport\CollectorException;
use App\Service\FailureReport\InstanceSecretBox;
use App\Tests\Double\FakeCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Phase 2 of failure reports (server#149, ADR 0012): with the admin's enablement and the reporter's consent, reports
 * go to the FetchDock collector (an in-memory FakeCollector here).
 */
class FailureReportForwardingTest extends ApiTestCase
{
    use SiteTestTrait;

    private HttpClient $http;

    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
        $this->http = static::createClient();
        $this->syncSites();
        FakeCollector::resetState();
        static::getContainer()->get('cache.app')->clear();
        $entityManager = $this->entityManager();
        $entityManager->createQuery(sprintf('DELETE FROM %s r', FailureReport::class))->execute();
        $entityManager->createQuery(sprintf('DELETE FROM %s s', FailureReportForwardingSettings::class))->execute();
        $this->transport()->reset();
    }

    public function testForwardingIsOffByDefault(): void
    {
        $settings = $this->http->request('GET', '/admin/failure_report_forwarding', ['headers' => self::auth('admin')])->toArray();
        $this->assertFalse($settings['enabled']);
        $this->assertSame('review', $settings['mode']);
        $this->assertTrue($settings['available']);
        $this->assertSame(FakeCollector::URL, $settings['collectorUrl']);
        $this->assertSame('1', $settings['policy']['version']);
        $this->assertNull($settings['instanceId']);

        $this->assertNull($this->wellKnown()['features']['failureReports']['upstreamForwarding']);

        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'shareUpstream' => true, 'upstreamPolicyVersion' => '1']);
        $this->assertResponseStatusCodeSame(422);
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('not_requested', $report['forwarding']);
        $this->assertFalse($report['shareUpstream']);
        $this->assertNull($report['upstreamId']);
        $this->assertSame([], FakeCollector::$reports);
    }

    public function testEnablingNeedsTheCurrentPolicyAndRegistersTheInstance(): void
    {
        $this->putSettings(['enabled' => true, 'mode' => 'review', 'acceptedPolicyVersion' => null]);
        $this->assertResponseStatusCodeSame(422);
        $this->putSettings(['enabled' => true, 'mode' => 'review', 'acceptedPolicyVersion' => '0']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], FakeCollector::$instances);

        $settings = $this->putSettings(['enabled' => true, 'mode' => 'review', 'acceptedPolicyVersion' => '1']);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($settings['enabled']);
        $this->assertSame('1', $settings['acceptedPolicyVersion']);
        $this->assertTrue($settings['acceptedPolicyCurrent']);
        $this->assertCount(1, FakeCollector::$instances);
        $this->assertSame(array_values(FakeCollector::$instances)[0], $settings['instanceId']);

        // The secret is stored encrypted and never serialized
        $stored = $this->entityManager()->find(FailureReportForwardingSettings::class, FailureReportForwardingSettings::ID);
        $secret = array_key_first(FakeCollector::$instances);
        $this->assertNotSame($secret, $stored->getEncryptedInstanceSecret());
        $this->assertSame($secret, static::getContainer()->get(InstanceSecretBox::class)->decrypt($stored->getEncryptedInstanceSecret()));
        $this->assertStringNotContainsString($secret, json_encode($settings));

        // Saving again keeps the registration
        $this->putSettings(['enabled' => true, 'mode' => 'automatic', 'acceptedPolicyVersion' => '1']);
        $this->assertCount(1, FakeCollector::$instances);

        $this->assertSame(
            ['privacyPolicyUrl' => FakeCollector::URL.'/policy', 'privacyPolicyVersion' => '1'],
            $this->wellKnown()['features']['failureReports']['upstreamForwarding'],
        );
    }

    public function testEnablingFailsWhileTheCollectorIsUnreachable(): void
    {
        FakeCollector::$failWith = 0;
        $this->putSettings(['enabled' => true, 'mode' => 'review', 'acceptedPolicyVersion' => '1']);
        $this->assertResponseStatusCodeSame(503);
        $this->assertFalse($this->http->request('GET', '/admin/failure_report_forwarding', ['headers' => self::auth('admin')])->toArray()['enabled']);
    }

    public function testReviewModeForwardsWithTheAdminsCommentOnly(): void
    {
        $this->enable('review');
        $report = $this->report([
            'kind' => 'unsupported_url',
            'url' => 'https://example.com/page',
            'comment' => 'Nothing happens',
            'userAgent' => 'Mozilla/5.0',
            'shareUpstream' => true,
            'upstreamPolicyVersion' => '1',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($report['shareUpstream']);
        $this->assertSame('awaiting_review', $report['forwarding']);
        $this->assertCount(0, $this->transport()->getSent(), 'review mode waits for an admin');

        $this->patchAdmin($report['id'], ['adminNote' => 'Internal: happens on every page']);
        $forwarded = $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/forward', [
            'json' => ['upstreamComment' => 'Seen 12 times this week'],
            'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(202);
        $this->assertSame('pending', $forwarded['forwarding']);
        $this->assertSame('Seen 12 times this week', $forwarded['upstreamComment']);

        $this->runQueued();

        $this->assertCount(1, FakeCollector::$reports);
        $upstreamId = array_key_first(FakeCollector::$reports);
        $payload = FakeCollector::$reports[$upstreamId]['payload'];
        $this->assertSame(1, $payload['schemaVersion']);
        $this->assertSame('unsupported_url', $payload['kind']);
        $this->assertSame('https://example.com/page', $payload['url']);
        $this->assertSame('example.com', $payload['host']);
        $this->assertSame('Nothing happens', $payload['comment']);
        $this->assertSame('Mozilla/5.0', $payload['userAgent']);
        $this->assertSame('Seen 12 times this week', $payload['adminComment']);
        $this->assertSame('1', $payload['consent']['policyVersion']);
        $this->assertSame('1', $payload['operatorPolicyVersion']);
        $encoded = json_encode($payload);
        foreach (['Internal', $report['id'], 'owner', 'downloadJob', 'adminNote'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }

        $own = $this->http->request('GET', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()])->toArray();
        $this->assertSame('forwarded', $own['forwarding']);
        $this->assertSame($upstreamId, $own['upstreamId']);
        $this->assertArrayNotHasKey('upstreamComment', $own);

        // Forwarding twice is a conflict
        $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/forward', ['json' => [], 'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(409);

        // Later comments for the developers
        $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/upstream_comments', [
            'json' => ['comment' => 'Still happening after the update'],
            'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $this->runQueued();
        $this->assertSame('Still happening after the update', FakeCollector::$comments[$upstreamId][0]['comment']);
        $admin = $this->http->request('GET', '/admin/failure_reports/'.$report['id'], ['headers' => self::auth('admin')])->toArray();
        $this->assertSame('forwarded', $admin['upstreamComments'][0]['state']);
        $this->assertSame([$report['id']], array_column(
            $this->http->request('GET', '/admin/failure_reports', ['query' => ['forwarding' => 'forwarded'], 'headers' => self::auth('admin')])->toArray()['member'],
            'id',
        ));

        // Withdrawing deletes the report here at once and at the collector asynchronously
        $this->http->request('DELETE', '/users/failure_reports/'.$report['id'], ['headers' => self::auth()]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->entityManager()->find(FailureReport::class, $report['id']));
        $this->assertCount(1, FakeCollector::$reports);
        $this->runQueued();
        $this->assertSame([], FakeCollector::$reports);
    }

    public function testReportsThatWereNotSharedCantBeForwardedOrCommented(): void
    {
        $this->enable('review');
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/private']);

        foreach (['forward' => [], 'upstream_comments' => ['comment' => 'x']] as $action => $body) {
            $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/'.$action, ['json' => $body, 'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json']]);
            $this->assertResponseStatusCodeSame(409, $action);
        }
        $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/forward', ['json' => [], 'headers' => self::auth('user') + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAutomaticModeForwardsAtOnce(): void
    {
        $this->enable('automatic');
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/auto', 'shareUpstream' => true, 'upstreamPolicyVersion' => '1']);
        $this->assertSame('pending', $report['forwarding']);
        $this->runQueued();

        $this->assertCount(1, FakeCollector::$reports);
        $this->assertNull(FakeCollector::$reports[array_key_first(FakeCollector::$reports)]['payload']['adminComment']);
    }

    public function testConsentNeedsAVersionTheCollectorAccepts(): void
    {
        $this->enable('automatic');
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'shareUpstream' => true]);
        $this->assertResponseStatusCodeSame(422);
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'shareUpstream' => true, 'upstreamPolicyVersion' => '0']);
        $this->assertResponseStatusCodeSame(422);
        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/a', 'upstreamPolicyVersion' => '1']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testReportsAreHeldWhenTheCollectorDropsTheConsentedVersion(): void
    {
        $this->enable('automatic');
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/held', 'shareUpstream' => true, 'upstreamPolicyVersion' => '1']);

        FakeCollector::$policyVersion = '2';
        FakeCollector::$acceptedVersions = ['2'];
        $this->runQueued();

        $this->assertSame([], FakeCollector::$reports);
        $admin = $this->http->request('GET', '/admin/failure_reports/'.$report['id'], ['headers' => self::auth('admin')])->toArray();
        $this->assertSame('awaiting_review', $admin['forwarding']);
        $this->assertStringContainsString('no longer accepts', $admin['forwardingError']);
    }

    public function testRefusedReportsFailAndUnreachableCollectorsRetry(): void
    {
        $this->enable('automatic');
        $report = $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/fail', 'shareUpstream' => true, 'upstreamPolicyVersion' => '1']);

        // Unreachable: the handler throws, so Messenger retries
        FakeCollector::$failWith = 503;
        $envelope = $this->transport()->getSent()[0];
        try {
            $this->handle($envelope->getMessage());
            $this->fail('An unreachable collector must throw');
        } catch (CollectorException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $this->assertSame('pending', $this->adminReport($report['id'])['forwarding']);

        // Once Messenger gives up, the report is marked failed
        static::getContainer()->get(FailureReportForwardingFailedListener::class)(new WorkerMessageFailedEvent($envelope, 'failure-reports', new \RuntimeException('down')));
        $failed = $this->adminReport($report['id']);
        $this->assertSame('failed', $failed['forwarding']);
        $this->assertStringContainsString('could not be reached', $failed['forwardingError']);

        // An admin tries again; a refusal (4xx) fails without retrying
        FakeCollector::$failWith = 422;
        $this->http->request('POST', '/admin/failure_reports/'.$report['id'].'/forward', ['json' => [], 'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json']]);
        $this->assertResponseStatusCodeSame(202);
        $this->transport()->reset();
        $this->handle(new ForwardFailureReportMessage($report['id']));
        $this->assertSame('failed', $this->adminReport($report['id'])['forwarding']);
    }

    public function testARejectedSecretRegistersTheInstanceAgain(): void
    {
        $this->enable('automatic');
        FakeCollector::$instances = [];

        $this->report(['kind' => 'unsupported_url', 'url' => 'https://example.com/again', 'shareUpstream' => true, 'upstreamPolicyVersion' => '1']);
        $this->runQueued();

        $this->assertCount(1, FakeCollector::$instances);
        $this->assertCount(1, FakeCollector::$reports);
        $this->assertSame(array_values(FakeCollector::$instances)[0], FakeCollector::$reports[array_key_first(FakeCollector::$reports)]['instance']);
    }

    public function testDeletingTheAccountDeletesForwardedReportsAtTheCollector(): void
    {
        $this->enable('automatic');
        $entityManager = $this->entityManager();
        $owner = new OidcSubjectIdentifier()->setSubject('forwarding-deletion-'.bin2hex(random_bytes(4)));
        $entityManager->persist($owner);
        $report = new FailureReport()
            ->setOwner($owner)
            ->setKind(\App\Enum\FailureReportKind::UNSUPPORTED_URL)
            ->setUrl('https://example.com/deleted-account')
            ->setHost('example.com')
            ->setCanonicalKey('example.com/deleted-account')
            ->setShareUpstream(true)
            ->setUpstreamPolicyVersion('1')
            ->setForwarding(\App\Enum\FailureReportForwarding::PENDING);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->handle(new ForwardFailureReportMessage($report->getId()->toRfc4122()));
        $this->assertCount(1, FakeCollector::$reports);

        static::getContainer()->get(AccountDeleter::class)->delete($this->entityManager()->find(OidcSubjectIdentifier::class, $owner->getId()));
        $this->runQueued();

        $this->assertSame([], FakeCollector::$reports);
    }

    public function testForwardingSettingsNeedRoleAdmin(): void
    {
        foreach (['user', 'viewer'] as $user) {
            $this->http->request('GET', '/admin/failure_report_forwarding', ['headers' => self::auth($user)]);
            $this->assertResponseStatusCodeSame(403);
        }
    }

    private function enable(string $mode): void
    {
        $this->putSettings(['enabled' => true, 'mode' => $mode, 'acceptedPolicyVersion' => '1']);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function putSettings(array $body): array
    {
        return $this->http->request('PUT', '/admin/failure_report_forwarding', [
            'json' => $body,
            'headers' => self::auth('admin') + ['Content-Type' => 'application/ld+json'],
        ])->toArray(false);
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

    private function patchAdmin(string $id, array $body): void
    {
        $this->http->request('PATCH', '/admin/failure_reports/'.$id, [
            'json' => $body,
            'headers' => self::auth('admin') + ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function adminReport(string $id): array
    {
        return $this->http->request('GET', '/admin/failure_reports/'.$id, ['headers' => self::auth('admin')])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function wellKnown(): array
    {
        return $this->http->request('GET', '/.well-known/browser-extension')->toArray();
    }

    /** Handles the queued messages, including the ones they queue */
    private function runQueued(): void
    {
        for ($round = 0; $round < 5; ++$round) {
            $sent = $this->transport()->getSent();
            if ([] === $sent) {
                return;
            }
            $this->transport()->reset();
            foreach ($sent as $envelope) {
                $this->handle($envelope->getMessage());
            }
        }
    }

    private function handle(object $message): void
    {
        $handler = match (true) {
            $message instanceof ForwardFailureReportMessage => ForwardFailureReportHandler::class,
            $message instanceof ForwardUpstreamCommentMessage => ForwardUpstreamCommentHandler::class,
            $message instanceof DeleteUpstreamReportMessage => DeleteUpstreamReportHandler::class,
        };
        static::getContainer()->get($handler)($message);
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.failure-reports');
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager;
    }
}
