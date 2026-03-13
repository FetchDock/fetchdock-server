<?php

namespace App\Tests\Unit\Security\Core\User;

use App\Entity\OidcSubjectIdentifier;
use App\Repository\OidcSubjectIdentifierRepository;
use App\Security\Core\User\OidcUser;
use App\Security\Core\User\OidcUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OidcUserProviderTest extends TestCase
{
    private MockObject|LoggerInterface $logger;
    private MockObject|OidcSubjectIdentifierRepository $oidcSubjectIdentifierRepository;
    private MockObject|EntityManagerInterface $entityManager;

    private OidcUserProvider $oidcUserProvider;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->oidcSubjectIdentifierRepository = $this->createMock(OidcSubjectIdentifierRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->oidcUserProvider = new OidcUserProvider(
            $this->logger,
            $this->oidcSubjectIdentifierRepository,
            $this->entityManager
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(OidcUserProvider::class, $this->oidcUserProvider);
    }

    public function testLoadUserByIdentifier()
    {
        $identifier = 'test_identifier';
        $attributes = [
            'issuer' => 'test_issuer',
            'sub' => 'test_sub',
            'email' => 'test_email@example.com',
            'preferred_username' => 'test_username',
            'groups' => [
                'user',
                'test',
                'admin'
            ],
            'name' => 'Test User',
            'given_name' => 'Test',
            'nickname' => 'Testy McTestface'
        ];

        $this->oidcSubjectIdentifierRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['subject' => 'test_sub'])
            ->willReturn(new OidcSubjectIdentifier()); // In reality, this would return an OidcSubjectIdentifier object

        $result = $this->oidcUserProvider->loadUserByIdentifier($identifier, $attributes);

        $this->assertInstanceOf(OidcUser::class, $result);
        $this->assertEquals('test_sub', $result->getUserIdentifier());
        $this->assertIsArray($result->getRoles());
        $this->assertContains('ROLE_USER', $result->getRoles());
        $this->assertContains('ROLE_TEST', $result->getRoles());
        $this->assertContains('ROLE_ADMIN', $result->getRoles());
    }

    public function testIdentifiedUserIdentifierIsStoredInDatabase()
    {
        $identifier = 'test_identifier';

        $this->oidcSubjectIdentifierRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['subject' => 'test_identifier'])
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(OidcSubjectIdentifier::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->oidcUserProvider->loadUserByIdentifier($identifier, []);

        $this->assertInstanceOf(OidcUser::class, $result);
        $this->assertEquals('test_identifier', $result->getUserIdentifier());
        $this->assertIsArray($result->getRoles());
        $this->assertContains('ROLE_USER', $result->getRoles());
    }

    public function testRefreshingUserDoesNothing()
    {
        $user = new OidcUser(
            issuer: 'test_issuer',
            sub: 'test_sub'
        );
        $this->assertEquals($user, $this->oidcUserProvider->refreshUser($user));
    }

    public function testSupportsClassSupportsOidcUser()
    {
        $this->assertTrue($this->oidcUserProvider->supportsClass(OidcUser::class));
    }

    public function testSupportsClassDoesNotSupportNonOidcUser()
    {
        $this->assertFalse($this->oidcUserProvider->supportsClass('NonOidcUser'));
    }

}
