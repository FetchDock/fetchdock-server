<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\InstanceIntegrityCheck;
use App\Enum\IntegrityRole;
use App\Enum\IntegrityStatus;
use App\Repository\PrivacyPolicySettingsRepository;
use App\Tests\Unit\Model\PrivacyPolicyAnswersTest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The privacy policy endpoints (ADR 0013): GET /privacy, /privacy_policy, the admin resources and the well-known
 * document.
 */
final class PrivacyPolicyTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    public function testUnavailableWhileTheCodeCannotBeVerified(): void
    {
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());

        $data = $this->client->request('GET', '/privacy_policy')->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('unavailable', $data['status']);
        $this->assertSame('integrity_unknown', $data['reason']);
        $this->assertNull($data['url']);
        $this->assertNull($data['revision']);

        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');
        $this->recordCheck(IntegrityStatus::MODIFIED, 'worker-1', IntegrityRole::WORKER, ['src/Kernel.php']);
        $data = $this->client->request('GET', '/privacy_policy')->toArray();
        $this->assertSame('integrity_modified', $data['reason']);
        $this->assertSame('modified', $data['integrity']['status']);

        $response = $this->client->request('GET', '/privacy', ['headers' => ['Accept' => 'text/html']]);
        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('found files that differ from the official build', $response->getContent(false));

        $wellKnown = $this->client->request('GET', '/.well-known/browser-extension')->toArray();
        $this->assertNull($wellKnown['privacyPolicyUrl']);
        $this->assertSame(['status' => 'modified', 'appVersion' => $data['appVersion']], $wellKnown['integrity']);
    }

    public function testUnavailableWhileTheAnswersAreIncomplete(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');
        $this->saveAnswers(['instanceName' => 'Family downloads']);

        $data = $this->client->request('GET', '/privacy_policy')->toArray();
        $this->assertSame('unavailable', $data['status']);
        $this->assertSame('incomplete', $data['reason']);

        $response = $this->client->request('GET', '/privacy', ['headers' => ['Accept' => 'text/html']]);
        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('has not completed the information', $response->getContent(false));
    }

    public function testPublishesTheGeneratedPolicyWhileVerifiedAndComplete(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());

        $data = $this->client->request('GET', '/privacy_policy')->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('generated', $data['status']);
        $this->assertNull($data['reason']);
        $this->assertSame('http://localhost/privacy', $data['url']);
        $this->assertSame(1, $data['revision']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $data['textVersion']);
        $this->assertNotNull($data['updatedAt']);
        $this->assertSame('verified', $data['integrity']['status']);
        $this->assertNotNull($data['integrity']['checkedAt']);
        // Clients learn the policy and the integrity status, nothing about the containers or the answers
        $this->assertSame(['status', 'reason', 'url', 'revision', 'textVersion', 'appVersion', 'updatedAt', 'integrity'], array_values(array_diff(array_keys($data), ['@context', '@id', '@type'])));
        $this->assertSame(['status', 'manifestDigest', 'gitSha', 'checkedAt'], array_values(array_diff(array_keys($data['integrity']), ['@type', '@id'])));

        $response = $this->client->request('GET', '/privacy', ['headers' => ['Accept' => 'text/html']]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('public', $response->getHeaders()['cache-control'][0]);
        $this->assertStringContainsString("default-src 'none'", $response->getHeaders()['content-security-policy'][0]);
        $html = $response->getContent();
        $this->assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $html);
        $this->assertStringContainsString('Revision 1 of', $html);
        $this->assertStringNotContainsString('an earlier version', $html);
        // The page forbids scripts, so Cloudflare Email Address Obfuscation must not rewrite contact/DPO addresses
        $this->assertMatchesRegularExpression('/<!--email_off-->.*privacy@example\.org.*<!--\/email_off-->/s', $html);

        $wellKnown = $this->client->request('GET', '/.well-known/browser-extension')->toArray();
        $this->assertSame('http://localhost/privacy', $wellKnown['privacyPolicyUrl']);
        $this->assertSame('verified', $wellKnown['integrity']['status']);
    }

    public function testEarlierRevisionsStayReadable(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');
        $this->saveAnswers(PrivacyPolicyAnswersTest::complete());
        $this->client->request('GET', '/privacy');
        $this->saveAnswers(['instanceName' => 'Our downloads'] + PrivacyPolicyAnswersTest::complete());

        $current = $this->client->request('GET', '/privacy')->getContent();
        $this->assertStringContainsString('<h1>Privacy policy of Our downloads</h1>', $current);
        $this->assertStringContainsString('Revision 2 of', $current);

        $first = $this->client->request('GET', '/privacy?revision=1')->getContent();
        $this->assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $first);
        $this->assertStringContainsString('an earlier version', $first);
        $this->assertStringContainsString('href="http://localhost/privacy"', $first);

        foreach (['3', 'abc', '-1'] as $revision) {
            $this->client->request('GET', '/privacy?revision='.$revision);
            $this->assertResponseStatusCodeSame(404);
        }

        // Still readable while no policy is published
        $this->recordCheck(IntegrityStatus::MODIFIED, 'api-2');
        $first = $this->client->request('GET', '/privacy?revision=1')->getContent();
        $this->assertStringContainsString('The instance publishes no current policy.', $first);
    }

    public function testAdminsManageTheAnswers(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');

        $this->client->request('GET', '/admin/privacy_policy', ['headers' => $this->auth('user')]);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('PUT', '/admin/privacy_policy', ['headers' => $this->auth('viewer'), 'json' => []]);
        $this->assertResponseStatusCodeSame(403);

        $data = $this->client->request('GET', '/admin/privacy_policy', ['headers' => $this->auth('admin')])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame(['instanceName', 'operator.name', 'operator.contactEmail', 'identityProvider.name', 'hosting.provider', 'hosting.country', 'retention.logsDays', 'legalBasis'], $data['missing']);
        $this->assertSame('incomplete', $data['reason']);
        $this->assertSame(['name' => null, 'address' => null, 'contactEmail' => null, 'dpoContact' => null], $this->withoutJsonLd($data['operator']));

        // Incomplete answers can be saved
        $data = $this->put(['instanceName' => 'Family downloads', 'operator' => ['name' => 'Alex Example']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Family downloads', $data['instanceName']);
        $this->assertNotContains('operator.name', $data['missing']);
        $this->assertContains('operator.contactEmail', $data['missing']);
        $this->assertNull($data['revision']);

        $answers = [
            'processors' => [['name' => 'Backup Co', 'purpose' => 'Off-site backups', 'country' => 'DE']],
            'additionalText' => 'We also **care**.',
        ] + PrivacyPolicyAnswersTest::complete();
        $data = $this->put($answers);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $data['missing']);
        $this->assertSame('generated', $data['status']);
        $this->assertSame(1, $data['revision']);
        $this->assertSame('DE', $data['processors'][0]['country']);
        $this->assertSame('verified', $data['integrity']['status']);

        $this->assertSame(1, $this->put($answers)['revision'], 'saving the same answers creates no revision');
        $this->assertSame(2, $this->put(['instanceName' => 'Our downloads'] + $answers)['revision']);

        // PUT replaces: omitted answers are cleared
        $data = $this->put(['instanceName' => 'Our downloads']);
        $this->assertSame([], $data['processors']);
        $this->assertSame('incomplete', $data['reason']);
    }

    public function testInvalidAnswersAreRejected(): void
    {
        $this->put([
            'operator' => ['contactEmail' => 'not an email'],
            'identityProvider' => ['privacyPolicyUrl' => 'javascript:alert(1)'],
            'hosting' => ['country' => 'nl'],
            'processors' => [['purpose' => 'no name']],
            'retention' => ['logsDays' => -1],
            'legalBasis' => 'consent',
            'additionalText' => str_repeat('a', 10001),
        ]);
        $this->assertResponseStatusCodeSame(422);
        $paths = array_column($this->client->getResponse()->toArray(false)['violations'], 'propertyPath');
        sort($paths);
        $this->assertSame(['additionalText', 'hosting.country', 'identityProvider.privacyPolicyUrl', 'legalBasis', 'operator.contactEmail', 'processors[0].name', 'retention.logsDays'], $paths);

        $this->put(['unknown' => true]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRevisionsAndPreview(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1');
        $answers = PrivacyPolicyAnswersTest::complete();
        $this->put($answers);
        $this->put(['instanceName' => 'Our downloads'] + $answers);

        $revisions = $this->client->request('GET', '/admin/privacy_policy/revisions', ['headers' => $this->auth('admin')])->toArray()['member'];
        $this->assertSame([2, 1], array_column($revisions, 'number'));
        $this->assertSame([true, false], array_column($revisions, 'current'));
        $this->assertSame(['answers', 'initial'], array_column($revisions, 'reason'));
        $this->assertArrayNotHasKey('html', $revisions[0]);

        $revision = $this->client->request('GET', '/admin/privacy_policy/revisions/1', ['headers' => $this->auth('admin')])->toArray();
        $this->assertStringContainsString('# Privacy policy of Family downloads', $revision['markdown']);
        $this->assertStringContainsString('<h1>Privacy policy of Family downloads</h1>', $revision['html']);
        $this->client->request('GET', '/admin/privacy_policy/revisions/9', ['headers' => $this->auth('admin')]);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/privacy_policy/revisions', ['headers' => $this->auth('viewer')]);
        $this->assertResponseStatusCodeSame(403);

        // The preview works while nothing is published and creates no revision
        $this->recordCheck(IntegrityStatus::MODIFIED, 'api-2');
        $this->put(['instanceName' => 'Draft'] + $answers);
        $response = $this->client->request('GET', '/admin/privacy_policy/preview', ['headers' => $this->auth('admin') + ['Accept' => 'text/html']]);
        $this->assertResponseIsSuccessful();
        $previewHtml = $response->getContent();
        $this->assertStringContainsString('It is not published.', $previewHtml);
        $this->assertStringContainsString('<h1>Privacy policy of Draft</h1>', $previewHtml);
        $this->assertMatchesRegularExpression('/<!--email_off-->.*privacy@example\.org.*<!--\/email_off-->/s', $previewHtml);
        $this->assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        $this->assertCount(2, $this->client->request('GET', '/admin/privacy_policy/revisions', ['headers' => $this->auth('admin')])->toArray()['member']);

        $this->client->request('GET', '/admin/privacy_policy/preview', ['headers' => $this->auth('viewer')]);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/privacy_policy/preview');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testInstanceIntegrityForAdmins(): void
    {
        $this->recordCheck(IntegrityStatus::VERIFIED, 'api-1', at: '-2 hours');
        $this->recordCheck(IntegrityStatus::MODIFIED, 'api-1', at: '-1 hour', mismatches: ['src/Kernel.php']);
        $this->recordCheck(IntegrityStatus::VERIFIED, 'worker-1', IntegrityRole::WORKER, at: '-3 hours');
        $this->recordCheck(IntegrityStatus::MODIFIED, 'old-1', at: '-49 hours');

        $data = $this->client->request('GET', '/admin/instance_integrity', ['headers' => $this->auth('admin')])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('modified', $data['status']);
        $this->assertIsBool($data['checkOnline']);
        $this->assertSame([['api-1', 'api', 'modified', ['src/Kernel.php']], ['worker-1', 'worker', 'verified', []]], array_map(
            static fn (array $check): array => [$check['host'], $check['role'], $check['status'], $check['mismatches']],
            $data['checks'],
        ));

        $this->client->request('GET', '/admin/instance_integrity', ['headers' => $this->auth('viewer')]);
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private function put(array $answers): array
    {
        return $this->client->request('PUT', '/admin/privacy_policy', [
            'headers' => $this->auth('admin') + ['Content-Type' => 'application/ld+json'],
            'json' => $answers,
        ])->toArray(false);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function saveAnswers(array $answers): void
    {
        $this->put($answers);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $mismatches
     */
    private function recordCheck(IntegrityStatus $status, string $host, IntegrityRole $role = IntegrityRole::API, array $mismatches = [], string $at = '-1 minute'): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new InstanceIntegrityCheck($host, $role, $status, str_repeat('a', 64), 'dev', $mismatches, new \DateTimeImmutable($at)));
        $entityManager->flush();
    }

    private function reset(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM instance_integrity_check');
        $connection->executeStatement('DELETE FROM privacy_policy_revision');
        static::getContainer()->get(PrivacyPolicySettingsRepository::class)->get();
        $connection->executeStatement("UPDATE privacy_policy_settings SET answers = '{}', answers_updated_at = NULL, view_all_role_seen_at = NULL");
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withoutJsonLd(array $data): array
    {
        return array_diff_key($data, ['@id' => true, '@type' => true, '@context' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $user): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass')];
    }
}
