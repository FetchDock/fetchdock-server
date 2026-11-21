<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\OidcSubjectIdentifier;
use App\Mercure\AdminTopics;
use Doctrine\ORM\EntityManagerInterface;

class SubscriptionTokenTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::$alwaysBootKernel = true;
    }

    public function testIssuesTokenForTheUsersOwnTopicsOnly(): void
    {
        $client = static::createClient();
        $before = time();

        $response = $client->request('POST', '/users/me/subscription-token', ['headers' => $this->auth('user', 'userpass')]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('cache-control', 'no-store, private');
        $data = $response->toArray();
        $expectedSelector = '/users/'.$this->user('user')->getUuid()->toRfc4122().'/{+path}';

        $this->assertSame([$expectedSelector], $data['topics']);
        $this->assertNotEmpty($data['hubUrl']);

        $claims = $this->decodeJwtPayload($data['token']);
        $this->assertSame([$expectedSelector], $claims['mercure']['subscribe']);
        $this->assertArrayNotHasKey('publish', $claims['mercure'], 'Subscriber tokens must not allow publishing');
        $ttl = self::getContainer()->getParameter('app.mercure.subscription_token_ttl');
        $this->assertEqualsWithDelta($before + $ttl, $claims['exp'], 5);
        $this->assertEqualsWithDelta($claims['exp'], new \DateTimeImmutable($data['expiresAt'])->getTimestamp(), 1);
    }

    public function testDifferentUsersGetDifferentTopics(): void
    {
        $client = static::createClient();

        $userTopics = $client->request('POST', '/users/me/subscription-token', ['headers' => $this->auth('user', 'userpass')])->toArray()['topics'];
        $adminTopics = $client->request('POST', '/users/me/subscription-token', ['headers' => $this->auth('admin', 'adminpass')])->toArray()['topics'];

        $this->assertNotSame($userTopics, $adminTopics);
    }

    public function testAdminsAlsoGetTheAdminTopics(): void
    {
        $client = static::createClient();

        $data = $client->request('POST', '/users/me/subscription-token', ['headers' => $this->auth('admin', 'adminpass')])->toArray();

        $adminSelector = self::getContainer()->get(AdminTopics::class)->subscribeSelector();
        $userSelector = '/users/'.$this->user('admin')->getUuid()->toRfc4122().'/{+path}';
        $this->assertSame([$userSelector, $adminSelector], $data['topics']);
        $this->assertSame([$userSelector, $adminSelector], $this->decodeJwtPayload($data['token'])['mercure']['subscribe']);
        // Same origin as the hub's public URL, which the admin uses as its topic base
        $this->assertSame(parse_url($data['hubUrl'], PHP_URL_SCHEME).'://'.parse_url($data['hubUrl'], PHP_URL_HOST), parse_url($adminSelector, PHP_URL_SCHEME).'://'.parse_url($adminSelector, PHP_URL_HOST));
        $this->assertStringEndsWith('/admin/{+path}', $adminSelector);
    }

    public function testRequiresAuthentication(): void
    {
        static::createClient()->request('POST', '/users/me/subscription-token');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testOnlyAllowsPost(): void
    {
        static::createClient()->request('GET', '/users/me/subscription-token', ['headers' => $this->auth('user', 'userpass')]);

        $this->assertResponseStatusCodeSame(405);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'Not a JWT');

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $subject): OidcSubjectIdentifier
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => $subject]);
        $this->assertNotNull($user, "Missing test fixture for subject '{$subject}', run `make prep-test`");

        return $user;
    }

    /**
     * @return array{Authorization: string}
     */
    private function auth(string $username, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode($username.':'.$password)];
    }
}
