<?php

namespace App\Tests\Unit\Security\Core\User;

use App\Security\Core\User\OidcUser;
use PHPUnit\Framework\TestCase;

class OidcUserTest extends TestCase
{
    public function testOidcUserAlwaysGetsTheRoleUserRole()
    {
        $oidcUser = new OidcUser(
            issuer: 'issuer',
            sub: 'sub',
            roles: ['role1', 'role2']
        );
        $roles = $oidcUser->getRoles();

        $this->assertIsArray($roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('role1', $roles);
        $this->assertContains('role2', $roles);
    }

    public function testGetUserIdentifierReturnsSub()
    {
        $oidcUser = new OidcUser(
            issuer: 'issuer',
            sub: 'sub',
            roles: ['role1', 'role2']
        );

        $this->assertSame('sub', $oidcUser->getUserIdentifier());
    }
}
