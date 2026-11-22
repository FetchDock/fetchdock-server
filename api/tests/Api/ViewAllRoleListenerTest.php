<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\EventListener\ViewAllRoleListener;
use App\Repository\PrivacyPolicySettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The privacy policy names users who may view all downloads only while there are any (ADR 0013).
 */
final class ViewAllRoleListenerTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    public function testRecordsNonAdminUsersWithTheViewAllRole(): void
    {
        $client = static::createClient();

        foreach (['user', 'admin'] as $user) {
            $client->request('GET', '/users/me', ['headers' => $this->auth($user)]);
            $this->assertResponseIsSuccessful();
        }
        self::assertNull($this->seenAt(), 'users without the role and administrators are not recorded');

        $client->request('GET', '/users/me', ['headers' => $this->auth('viewer')]);
        $this->assertResponseIsSuccessful();
        $seenAt = $this->seenAt();
        self::assertNotNull($seenAt);
        self::assertEqualsWithDelta(time(), $seenAt->getTimestamp(), 60);
    }

    private function seenAt(): ?\DateTimeImmutable
    {
        $value = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT view_all_role_seen_at FROM privacy_policy_settings');

        return false === $value || null === $value ? null : new \DateTimeImmutable($value);
    }

    private function reset(): void
    {
        self::bootKernel();
        static::getContainer()->get('cache.app')->deleteItem(ViewAllRoleListener::CACHE_KEY);
        static::getContainer()->get(PrivacyPolicySettingsRepository::class)->get();
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE privacy_policy_settings SET view_all_role_seen_at = NULL');
        self::ensureKernelShutdown();
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $user): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass')];
    }
}
