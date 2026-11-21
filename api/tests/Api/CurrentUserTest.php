<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\OidcSubjectIdentifier;
use Doctrine\ORM\EntityManagerInterface;

class CurrentUserTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public function testReturnsTheUserWithTheirEffectiveRoles(): void
    {
        $client = static::createClient();

        $data = $client->request('GET', '/users/me', ['headers' => $this->auth('admin')])->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('admin', $data['subject']);
        $owner = static::getContainer()->get(EntityManagerInterface::class)->getRepository(OidcSubjectIdentifier::class)->findOneBy(['subject' => 'admin']);
        $this->assertSame($owner->getUuid()->toRfc4122(), $data['uuid']);
        // Granted through role_hierarchy
        $this->assertContains('ROLE_ADMIN', $data['roles']);
        $this->assertContains('ROLE_MANAGE_ALL_DOWNLOADS', $data['roles']);
        $this->assertContains('ROLE_VIEW_ALL_DOWNLOADS', $data['roles']);
        $this->assertContains('ROLE_USER', $data['roles']);
    }

    public function testRegularUsersHaveNoAdminPermissions(): void
    {
        $client = static::createClient();

        $data = $client->request('GET', '/users/me', ['headers' => $this->auth('user')])->toArray();

        $this->assertSame(['ROLE_USER'], $data['roles']);
    }

    public function testViewersOnlyHaveTheViewPermission(): void
    {
        $client = static::createClient();

        $data = $client->request('GET', '/users/me', ['headers' => $this->auth('viewer')])->toArray();

        $this->assertSame(['ROLE_USER', 'ROLE_VIEW_ALL_DOWNLOADS'], $data['roles']);
        $this->assertNull($data['uuid']);
    }

    public function testRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/users/me');

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @return array{Authorization: string, Accept: string}
     */
    private function auth(string $user): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$user.'pass'), 'Accept' => 'application/json'];
    }
}
